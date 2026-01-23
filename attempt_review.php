<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$pdo = db();
ensure_attempts_grade($pdo);

$teacher = $_SESSION['user'];
$attempt_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($attempt_id <= 0) {
    http_response_code(400);
    die('Липсва идентификатор на опит.');
}

// Load attempt with ownership check
$stmt = $pdo->prepare('SELECT atp.*, a.title AS assignment_title, a.assigned_by_teacher_id, t.title AS test_title,
                              u.first_name, u.last_name, u.email
                       FROM attempts atp
                       JOIN assignments a ON a.id = atp.assignment_id
                       JOIN tests t ON t.id = atp.test_id
                       JOIN users u ON u.id = atp.student_id
                       WHERE atp.id = :id LIMIT 1');
$stmt->execute([':id' => $attempt_id]);
$attempt = $stmt->fetch();
if (!$attempt || (int) $attempt['assigned_by_teacher_id'] !== (int) $teacher['id']) {
    http_response_code(403);
    die('Нямате достъп до този опит.');
}

// Update teacher_grade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teacher_grade'])) {
    $grade = $_POST['teacher_grade'] !== '' ? (int) $_POST['teacher_grade'] : null;
    if ($grade === null || ($grade >= 2 && $grade <= 6)) {
        $pdo->prepare('UPDATE attempts SET teacher_grade = :g WHERE id = :id')->execute([':g' => $grade, ':id' => $attempt_id]);
        // Reload
        $stmt->execute([':id' => $attempt_id]);
        $attempt = $stmt->fetch();
    }
}

// Functions moved to lib/helpers.php

$p = percent($attempt['score_obtained'], $attempt['max_score']);
$autoGrade = grade_from_percent($p);

// Load questions in the test
$qStmt = $pdo->prepare('SELECT qb.*, tq.points, tq.order_index FROM test_questions tq JOIN question_bank qb ON qb.id = tq.question_id WHERE tq.test_id = :tid ORDER BY tq.order_index');
$qStmt->execute([':tid' => (int) $attempt['test_id']]);
$questions = $qStmt->fetchAll();

// Answers per question
$answersByQ = [];
if ($questions) {
    $ids = array_map(fn($r) => (int) $r['id'], $questions);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = $pdo->prepare("SELECT * FROM answers WHERE question_id IN ($in) ORDER BY order_index");
    $aStmt->execute($ids);
    while ($a = $aStmt->fetch()) {
        $answersByQ[(int) $a['question_id']][] = $a;
    }
}

// Attempt answers
$aa = [];
$aaStmt = $pdo->prepare('SELECT * FROM attempt_answers WHERE attempt_id = :att');
$aaStmt->execute([':att' => $attempt_id]);
while ($row = $aaStmt->fetch()) {
    $aa[(int) $row['question_id']] = $row;
}
?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Преглед на опит – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= time() ?>">
    <style>
        .q-card {
            border-left: 4px solid var(--tg-primary);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .q-card:hover {
            transform: translateY(-2px);
        }

        .ans-row {
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            margin-bottom: 0.5rem;
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid transparent;
            transition: all 0.2s;
        }

        .ans-row:hover {
            background: rgba(255, 255, 255, 0.8);
        }

        .ans-correct-icon {
            color: var(--success-strong, #15803d);
        }

        .ans-wrong-icon {
            color: var(--danger-strong, #b42318);
        }

        /* Selected answer styling */
        .ans-selected {
            background: var(--surface-2, #eef2ff);
            border-color: var(--focus-ring, rgba(37, 99, 235, 0.38));
        }
    </style>
</head>

<body class="bg-body">
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-5">
        <!-- Header -->
        <div
            class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-5 animate-fade-up">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span
                        class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill text-uppercase tracking-wider small px-3">
                        Опит #<?= (int) $attempt['id'] ?>
                    </span>
                    <span
                        class="text-muted small"><?= htmlspecialchars($attempt['submitted_at'] ? format_date($attempt['submitted_at']) : format_date($attempt['started_at'])) ?></span>
                </div>
                <h1 class="display-6 fw-bold m-0">
                    <?= htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']) ?>
                </h1>
                <div class="text-muted mt-1">
                    <i class="bi bi-folder2-open me-1"></i> <?= htmlspecialchars($attempt['assignment_title']) ?>
                    <span class="mx-2">•</span>
                    <i class="bi bi-file-text me-1"></i> <?= htmlspecialchars($attempt['test_title']) ?>
                </div>
            </div>
            <a href="assignment_overview.php?id=<?= (int) $attempt['assignment_id'] ?>"
                class="btn btn-outline-secondary rounded-pill px-4 hover-lift">
                <i class="bi bi-arrow-left me-2"></i> Назад към списъка
            </a>
        </div>

        <!-- Score Card -->
        <div class="glass-card p-4 mb-5 animate-fade-up delay-100 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 p-5 opacity-10 pointer-events-none">
                <i class="bi bi-trophy display-1"></i>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-4 gap-lg-5 position-relative">
                <div>
                    <div class="text-muted small text-uppercase tracking-wider fw-bold mb-1">Резултат</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <span class="display-4 fw-bold text-body"><?= (float) $attempt['score_obtained'] ?></span>
                        <span class="text-muted fs-4">/ <?= (float) $attempt['max_score'] ?> т.</span>
                    </div>
                </div>

                <div class="d-flex flex-column align-items-start">
                    <div class="text-muted small text-uppercase tracking-wider fw-bold mb-1">Процент</div>
                    <div class="fs-2 fw-bold <?= $p >= 50 ? 'text-success' : 'text-danger' ?>">
                        <?= $p !== null ? $p . '%' : '—' ?>
                    </div>
                </div>

                <div class="d-flex flex-column align-items-start">
                    <div class="text-muted small text-uppercase tracking-wider fw-bold mb-1">Автоматична Оценка</div>
                    <div>
                        <span
                            class="badge bg-primary fs-5 rounded-pill px-4 shadow-sm"><?= $autoGrade !== null ? $autoGrade : '—' ?></span>
                    </div>
                </div>

                <div class="ms-lg-auto border-start ps-lg-4 border-light">
                    <form method="post" class="d-flex flex-column gap-2">
                        <label class="small text-muted text-uppercase tracking-wider fw-bold">Оценка от Учител</label>
                        <div class="d-flex gap-2">
                            <select name="teacher_grade" class="form-select border-0 bg-white"
                                style="min-width: 100px;">
                                <option value="">—</option>
                                <?php for ($g = 2; $g <= 6; $g++): ?>
                                    <option value="<?= $g ?>" <?= ($attempt['teacher_grade'] !== null && (int) $attempt['teacher_grade'] === $g) ? 'selected' : '' ?>>Оценка <?= $g ?></option>
                                <?php endfor; ?>
                            </select>
                            <button class="btn btn-primary px-3 shadow-sm" type="submit"><i
                                    class="bi bi-save"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Questions List -->
        <div class="d-flex flex-column gap-4 animate-fade-up delay-200">
            <?php foreach ($questions as $idx => $q):
                $qid = (int) $q['id'];
                $studentAns = $aa[$qid] ?? null;
                $answers = $answersByQ[$qid] ?? []; ?>
                <div class="glass-card p-4 q-card position-relative">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5 class="fw-bold text-secondary mb-0">Въпрос <?= $idx + 1 ?></h5>
                        <div class="d-flex gap-2">
                            <span class="badge bg-light text-secondary border"><?= (float) $q['points'] ?> т.</span>
                            <?php if ($studentAns): ?>
                                <?php if ((int) $studentAns['is_correct'] === 1): ?>
                                    <span
                                        class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><i
                                            class="bi bi-check-circle me-1"></i> Верен</span>
                                <?php else: ?>
                                    <span
                                        class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25"><i
                                            class="bi bi-x-circle me-1"></i> Грешен</span>
                                <?php endif; ?>
                                <span
                                    class="badge bg-secondary bg-opacity-10 text-dark border"><?= (float) $studentAns['score_awarded'] ?>
                                    т.</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">Неотговорен</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-4 fs-5"><?= nl2br(htmlspecialchars($q['body'])) ?></div>

                    <?php if (!empty($q['media_url'])): ?>
                        <div class="mb-4">
                            <img src="<?= htmlspecialchars($q['media_url']) ?>" alt="Media"
                                class="img-fluid rounded-4 shadow-sm border" style="max-height: 400px;">
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-column gap-2">
                        <?php if (in_array($q['qtype'], ['single_choice', 'true_false'], true)): ?>
                            <?php foreach ($answers as $a):
                                $isSelected = $studentAns && (string) $studentAns['selected_option_ids'] === (string) $a['id'];
                                $isCorrect = (int) $a['is_correct'] === 1;
                                ?>
                                <div class="ans-row d-flex align-items-center gap-3 <?= $isSelected ? 'ans-selected' : '' ?>">
                                    <?php if ($isCorrect): ?>
                                        <i class="bi bi-check-circle-fill ans-correct-icon fs-5"></i>
                                    <?php elseif ($isSelected && !$isCorrect): ?>
                                        <i class="bi bi-x-circle-fill ans-wrong-icon fs-5"></i>
                                    <?php else: ?>
                                        <i class="bi bi-circle text-muted fs-5 opacity-50"></i>
                                    <?php endif; ?>

                                    <span class="<?= $isCorrect ? 'fw-bold text-success' : ($isSelected ? 'text-danger' : '') ?>">
                                        <?= htmlspecialchars($a['content']) ?>
                                    </span>

                                    <?php if ($isSelected): ?>
                                        <span class="badge bg-primary rounded-pill ms-auto">Избран от ученика</span>
                                    <?php endif; ?>
                                    <?php if ($isCorrect && !$isSelected): ?>
                                        <span
                                            class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill ms-auto">Верен
                                            отговор</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                        <?php elseif ($q['qtype'] === 'multiple_choice'): ?>
                            <?php
                            $sels = $studentAns && $studentAns['selected_option_ids'] ? array_map('intval', explode(',', $studentAns['selected_option_ids'])) : [];
                            ?>
                            <?php foreach ($answers as $a):
                                $isSelected = in_array((int) $a['id'], $sels, true);
                                $isCorrect = (int) $a['is_correct'] === 1;
                                ?>
                                <div class="ans-row d-flex align-items-center gap-3 <?= $isSelected ? 'ans-selected' : '' ?>">
                                    <?php if ($isCorrect): ?>
                                        <i class="bi bi-check-square-fill ans-correct-icon fs-5"></i>
                                    <?php elseif ($isSelected && !$isCorrect): ?>
                                        <i class="bi bi-x-square-fill ans-wrong-icon fs-5"></i>
                                    <?php else: ?>
                                        <i class="bi bi-square text-muted fs-5 opacity-50"></i>
                                    <?php endif; ?>

                                    <span class="<?= $isCorrect ? 'fw-bold text-success' : ($isSelected ? 'text-danger' : '') ?>">
                                        <?= htmlspecialchars($a['content']) ?>
                                    </span>

                                    <?php if ($isSelected): ?>
                                        <span class="badge bg-primary rounded-pill ms-auto">Избран</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                        <?php elseif ($q['qtype'] === 'short_answer'): ?>
                            <div class="p-3 bg-light rounded-3 border mb-2">
                                <div class="text-muted small text-uppercase tracking-wider fw-bold mb-1">Отговор на ученика
                                </div>
                                <div class="fs-5 fw-medium"><?= htmlspecialchars($studentAns['free_text'] ?? '—') ?></div>
                            </div>
                            <?php if ($answers): ?>
                                <div
                                    class="p-3 bg-success bg-opacity-10 rounded-3 border border-success border-opacity-25 text-success">
                                    <div class="small text-uppercase tracking-wider fw-bold mb-1 op-75">Допустими верни отговори
                                    </div>
                                    <div><?= htmlspecialchars(implode(', ', array_map(fn($x) => $x['content'], $answers))) ?></div>
                                </div>
                            <?php endif; ?>

                        <?php elseif ($q['qtype'] === 'numeric'): ?>
                            <div class="p-3 bg-light rounded-3 border mb-2">
                                <div class="text-muted small text-uppercase tracking-wider fw-bold mb-1">Числен отговор на
                                    ученика</div>
                                <div class="fs-5 fw-medium font-monospace">
                                    <?= htmlspecialchars($studentAns['numeric_value'] ?? '—') ?>
                                </div>
                            </div>
                            <?php if (!empty($answers[0]['content'])): ?>
                                <div
                                    class="p-3 bg-success bg-opacity-10 rounded-3 border border-success border-opacity-25 text-success">
                                    <div class="small text-uppercase tracking-wider fw-bold mb-1 op-75">Верен отговор</div>
                                    <div class="font-monospace fw-bold"><?= htmlspecialchars($answers[0]['content']) ?></div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!empty($q['explanation'])): ?>
                            <div
                                class="mt-3 p-3 bg-info bg-opacity-10 rounded-3 border border-info border-opacity-25 text-info-emphasis">
                                <div class="d-flex gap-2">
                                    <i class="bi bi-lightbulb-fill mt-1"></i>
                                    <div>
                                        <div class="fw-bold small text-uppercase tracking-wider mb-1">Обяснение</div>
                                        <div><?= htmlspecialchars($q['explanation']) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <?php include __DIR__ . '/components/footer.php'; ?>
</body>

</html>