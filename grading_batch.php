<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

require_role('teacher');
$user = $_SESSION['user'];
$pdo = db();

// Input: Assignment ID or specific question queue
$assignment_id = isset($_GET['assignment_id']) ? (int) $_GET['assignment_id'] : 0;
// We can also filter by specific question ID if the teacher wants to grade "Question 3 for all students"
$question_id = isset($_GET['question_id']) ? (int) $_GET['question_id'] : 0;

$assignment = null;
if ($assignment_id > 0) {
    $stmt = $pdo->prepare('SELECT a.*, t.title as test_title FROM assignments a JOIN tests t ON t.id = a.test_id WHERE a.id = :id AND a.assigned_by_teacher_id = :tid');
    $stmt->execute([':id' => $assignment_id, ':tid' => $user['id']]);
    $assignment = $stmt->fetch();
}

if (!$assignment) {
    // If no assignment selected, show a "Select Queue" screen
    // For MVP flow, redirect to dashboard or show error
    header('Location: dashboard.php'); // Simplification
    exit;
}

// Stats for Progress Bar
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN aa.score_awarded IS NOT NULL AND aa.score_awarded > 0 THEN 1 ELSE 0 END) as graded
    FROM attempt_answers aa
    JOIN attempts atp ON atp.id = aa.attempt_id
    WHERE atp.assignment_id = :aid
    AND ($question_id = 0 OR aa.question_id = :qid)
");
$statsStmt->execute([':aid' => $assignment_id, ':qid' => $question_id]);
$stats = $statsStmt->fetch();
$progress = $stats['total'] > 0 ? round(($stats['graded'] / $stats['total']) * 100) : 0;

// Fetch Next Ungraded Item
// Priority: Ungraded first, then order by attempt date
$sql = "
    SELECT 
        aa.id as answer_id,
        aa.question_id,
        aa.free_text,
        aa.numeric_value,
        aa.selected_option_ids,
        aa.score_awarded,
        aa.is_correct,
        atp.id as attempt_id,
        atp.student_id,
        u.first_name,
        u.last_name,
        qb.body as question_body,
        qb.qtype,
        qb.media_url,
        tq.points as max_points,
        (SELECT content FROM answers WHERE question_id = aa.question_id AND is_correct = 1 LIMIT 1) as correct_ref
    FROM attempt_answers aa
    JOIN attempts atp ON atp.id = aa.attempt_id
    JOIN users u ON u.id = atp.student_id
    JOIN question_bank qb ON qb.id = aa.question_id
    JOIN test_questions tq ON tq.question_id = aa.question_id AND tq.test_id = atp.test_id
    WHERE atp.assignment_id = :aid
    AND ($question_id = 0 OR aa.question_id = :qid)
    ORDER BY (aa.score_awarded IS NULL) DESC, atp.submitted_at ASC
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':aid' => $assignment_id, ':qid' => $question_id]);
$item = $stmt->fetch();

// Handle AJAX Grade Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'grade') {
    header('Content-Type: application/json');
    $ansId = (int) $_POST['answer_id'];
    $points = (float) $_POST['points'];

    // Update attempt_answers
    $upd = $pdo->prepare('UPDATE attempt_answers SET score_awarded = :p, is_correct = :c WHERE id = :id');
    // Determine is_correct loosely based on if points > 0 ?? Or just manual override.
    // Let's assume proportional check not needed here, strictly manual override.
    // If points == max_points -> correct, else if > 0 partial, else incorrect.
    // We need max points.
    $check = $pdo->prepare('SELECT tq.points FROM attempt_answers aa JOIN attempts atp ON atp.id = aa.attempt_id JOIN test_questions tq ON tq.question_id = aa.question_id AND tq.test_id = atp.test_id WHERE aa.id = :id');
    $check->execute([':id' => $ansId]);
    $max = (float) $check->fetchColumn();

    $isCorrect = abs($points - $max) < 0.01 ? 1 : 0;

    $upd->execute([':p' => $points, ':c' => $isCorrect, ':id' => $ansId]);

    // Update Attempt Total Score
    // Trigger attempt score recalc
    $pdo->prepare('UPDATE attempts a 
                   SET score_obtained = (SELECT SUM(score_awarded) FROM attempt_answers WHERE attempt_id = a.id)
                   WHERE id = (SELECT attempt_id FROM attempt_answers WHERE id = :id)')
        ->execute([':id' => $ansId]);

    echo json_encode(['status' => 'success']);
    exit;
}

?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Rapid Review – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= time() ?>">
    <style>
        body {
            overflow: hidden;
            height: 100vh;
        }

        /* Focus Mode */
        .zen-container {
            max-width: 800px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .student-answer {
            font-size: 1.5rem;
            line-height: 1.6;
            font-family: 'Georgia', serif;
            /* Visual distinction for user content */
            color: var(--tg-primary);
        }

        .animate-slide {
            transition: transform 0.3s cubic-bezier(0.4, 0.0, 0.2, 1);
        }
    </style>
</head>

<body class="bg-body">

    <!-- Minimal Header -->
    <header class="position-fixed top-0 start-0 min-vw-100 p-3 z-3">
        <div class="d-flex justify-content-between align-items-center container-fluid">
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill border-0"><i
                    class="bi bi-x-lg"></i> Изход</a>
            <div class="text-center">
                <div class="fw-bold text-muted small text-uppercase tracking-wider">Оценяване на</div>
                <div class="fw-bold">
                    <?= htmlspecialchars($assignment['test_title']) ?>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2" style="width: 200px;">
                <div class="progress w-100" style="height: 6px; background: rgba(0,0,0,0.05);">
                    <div class="progress-bar bg-success rounded-pill" style="width: <?= $progress ?>%"></div>
                </div>
                <span class="small text-muted">
                    <?= $progress ?>%
                </span>
            </div>
        </div>
    </header>

    <main class="zen-container p-4">
        <?php if (!$item): ?>
            <!-- Empty State / Done -->
            <div class="text-center animate-fade-up">
                <div class="display-1 text-success mb-4"><i class="bi bi-check-circle-fill"></i></div>
                <h1 class="display-6 fw-bold mb-3">Всичко е оценено!</h1>
                <p class="text-muted lead mb-5">Няма повече отговори за проверка в тази опашка.</p>
                <a href="dashboard.php" class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm">Към таблото</a>
            </div>
        <?php else: ?>
            <!-- Grading Card -->
            <div id="grading-card" class="glass-card p-5 shadow-lg position-relative overflow-hidden">
                <!-- Meta -->
                <div class="d-flex justify-content-between align-items-start mb-4 opacity-75">
                    <div class="d-flex gap-2 align-items-center">
                        <span
                            class="avatar-initials rounded-circle bg-secondary bg-opacity-10 text-secondary fw-bold small d-flex align-items-center justify-content-center"
                            style="width: 32px; height: 32px;">
                            <?= mb_substr($item['first_name'], 0, 1) ?>
                        </span>
                        <span>
                            <?= htmlspecialchars($item['first_name'] . ' ' . $item['last_name']) ?>
                        </span>
                    </div>
                    <span class="badge bg-light text-secondary border">
                        <?= $item['qtype'] === 'short_answer' ? 'Свободен текст' : 'Въпрос' ?>
                    </span>
                </div>

                <!-- Question -->
                <div class="mb-5 text-muted">
                    <?= nl2br(htmlspecialchars($item['question_body'])) ?>
                </div>

                <!-- Answer -->
                <div class="mb-5 p-4 rounded-3 bg-white bg-opacity-50 border border-secondary border-opacity-10">
                    <div class="text-uppercase small text-muted fw-bold mb-2">Отговор на ученика:</div>
                    <div class="student-answer">
                        <?php
                        if ($item['qtype'] === 'short_answer')
                            echo nl2br(htmlspecialchars($item['free_text'] ?? ''));
                        elseif ($item['qtype'] === 'numeric')
                            echo $item['numeric_value'];
                        else
                            echo '<em class="text-muted">(Автоматично проверяем тип)</em>';
                        ?>
                    </div>
                </div>

                <!-- Reference (Collapsible) -->
                <?php if (!empty($item['correct_ref'])): ?>
                    <div class="mb-4">
                        <button class="btn btn-sm btn-link text-decoration-none text-muted p-0" type="button"
                            data-bs-toggle="collapse" data-bs-target="#refCollapse">
                            <i class="bi bi-eye me-1"></i> Виж верния отговор
                        </button>
                        <div class="collapse mt-2" id="refCollapse">
                            <div class="alert alert-info bg-opacity-10 border-0 small">
                                <?= htmlspecialchars($item['correct_ref']) ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="d-flex gap-3 align-items-center pt-3 border-top border-secondary border-opacity-10">
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted fw-bold">Точки (Макс:
                            <?= (float) $item['max_points'] ?>)
                        </label>
                        <input type="number" id="gradeInput" class="form-control form-control-lg fw-bold" step="0.5"
                            max="<?= (float) $item['max_points'] ?>" value="<?= (float) ($item['score_awarded'] ?? 0) ?>"
                            autofocus>
                    </div>
                    <div class="d-flex gap-2 align-self-end">
                        <button class="btn btn-outline-danger btn-lg" onclick="submitGrade(0)" title="0 точки"><i
                                class="bi bi-x-lg"></i></button>
                        <button class="btn btn-outline-success btn-lg"
                            onclick="submitGrade(<?= (float) $item['max_points'] ?>)" title="Макс точки"><i
                                class="bi bi-check-lg"></i></button>
                        <div class="vr mx-2 opacity-25"></div>
                        <button class="btn btn-primary btn-lg rounded-pill px-4" onclick="submitGrade(getValue())">
                            Запази & Следващ <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="text-center mt-3 text-muted small opacity-50">
                Натисни <strong>Enter</strong> за да запазиш и продължиш.
            </div>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function getValue() {
            return document.getElementById('gradeInput').value;
        }

        function submitGrade(points) {
            const card = document.getElementById('grading-card');
            card.style.transform = 'translateX(-50px)';
            card.style.opacity = '0';

            // Send AJAX
            const formData = new FormData();
            formData.append('action', 'grade');
            formData.append('answer_id', '<?= $item['answer_id'] ?? 0 ?>');
            formData.append('points', points);

            fetch('grading_batch.php?assignment_id=<?= $assignment_id ?>&question_id=<?= $question_id ?>', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        window.location.reload(); // Reload for next item (Simplest "Next" logic for MVP)
                    }
                });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submitGrade(getValue());
            }
        });
    </script>
</body>

</html>