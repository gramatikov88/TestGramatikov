<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$pdo = db();
$student = $_SESSION['user'];

$attempt_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attempt_id <= 0) { http_response_code(400); die('Липсва идентификатор на опит.'); }

// Load attempt with assignment and test; ensure ownership by current student
$stmt = $pdo->prepare('SELECT atp.*, a.title AS assignment_title, a.open_at, a.due_at, a.close_at, t.title AS test_title
                       FROM attempts atp
                       JOIN assignments a ON a.id = atp.assignment_id
                       JOIN tests t ON t.id = atp.test_id
                       WHERE atp.id = :id AND atp.student_id = :sid LIMIT 1');
$stmt->execute([':id'=>$attempt_id, ':sid'=>$student['id']]);
$attempt = $stmt->fetch();
if (!$attempt) { http_response_code(404); die('Опитът не е намерен.'); }

// Determine if correct answers can be shown: after due_at or close_at
$now = time();
$show_keys = false;
if (!empty($attempt['due_at']) && strtotime($attempt['due_at']) < $now) $show_keys = true;
if (!empty($attempt['close_at']) && strtotime($attempt['close_at']) < $now) $show_keys = true;

// Load questions in the test
$qStmt = $pdo->prepare('SELECT qb.*, tq.points, tq.order_index FROM test_questions tq JOIN question_bank qb ON qb.id = tq.question_id WHERE tq.test_id = :tid ORDER BY tq.order_index');
$qStmt->execute([':tid' => (int)$attempt['test_id']]);
$questions = $qStmt->fetchAll();

// Answers per question
$answersByQ = [];
if ($questions) {
    $ids = array_map(fn($r)=> (int)$r['id'], $questions);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = $pdo->prepare("SELECT * FROM answers WHERE question_id IN ($in) ORDER BY order_index");
    $aStmt->execute($ids);
    while ($a = $aStmt->fetch()) { $answersByQ[(int)$a['question_id']][] = $a; }
}

// Attempt answers
$aa = [];
$aaStmt = $pdo->prepare('SELECT * FROM attempt_answers WHERE attempt_id = :att');
$aaStmt->execute([':att'=>$attempt_id]);
while ($row = $aaStmt->fetch()) { $aa[(int)$row['question_id']] = $row; }

function percent($s, $m) { if ($m > 0 && $s !== null) return round(($s/$m)*100,2); return null; }
function grade_from_percent($p){ if ($p===null) return null; if ($p>=90) return 6; if ($p>=80) return 5; if ($p>=65) return 4; if ($p>=50) return 3; return 2; }
$p = percent($attempt['score_obtained'], $attempt['max_score']);
$grade = grade_from_percent($p);
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Преглед на опит – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .q-card { border-left: 4px solid #0d6efd; }
        .ans-correct { color: #198754; }
        .ans-wrong { color: #dc3545; }
        .muted-badge { background: #f1f3f5; color: #495057; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h5 m-0">Вашият опит #<?= (int)$attempt['id'] ?></h1>
            <div class="text-muted">Задание: <?= htmlspecialchars($attempt['assignment_title']) ?> • Тест: <?= htmlspecialchars($attempt['test_title']) ?></div>
            <div class="text-muted small">Предаден: <?= htmlspecialchars($attempt['submitted_at'] ?: $attempt['started_at']) ?></div>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Табло</a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div>Резултат: <span class="fw-semibold"><?= (float)$attempt['score_obtained'] ?>/<?= (float)$attempt['max_score'] ?></span> (<?= $p !== null ? $p.'%' : '—' ?>)</div>
            <div>Оценка: <span class="badge bg-primary"><?= $grade !== null ? $grade : '—' ?></span></div>
            <?php if (!$show_keys): ?>
                <div class="text-muted">Верните отговори ще се покажат след крайния срок.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($questions as $idx => $q): $qid = (int)$q['id']; $studentAns = $aa[$qid] ?? null; $answers = $answersByQ[$qid] ?? []; ?>
        <div class="card shadow-sm mb-3 q-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div><strong>Въпрос <?= $idx+1 ?>.</strong> <?= nl2br(htmlspecialchars($q['body'])) ?></div>
                    <div>
                        <span class="badge muted-badge"><?= (float)$q['points'] ?> т.</span>
                        <?php if ($studentAns): ?>
                            <span class="badge <?= ((int)$studentAns['is_correct']===1)?'bg-success':'bg-danger' ?> ms-1">
                                <?= ((int)$studentAns['is_correct']===1)?'Верен':'Грешен' ?>
                            </span>
                            <span class="badge bg-secondary ms-1"><?= (float)$studentAns['score_awarded'] ?> т.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($q['media_url'])): ?>
                    <div class="question-media mb-3">
                        <img src="<?= htmlspecialchars($q['media_url']) ?>" alt="Media" class="img-fluid rounded border">
                    </div>
                <?php endif; ?>
                <div class="mt-2">
                    <?php if (in_array($q['qtype'], ['single_choice','true_false'], true)): ?>
                        <?php foreach ($answers as $a): $sel = $studentAns && (string)$studentAns['selected_option_ids'] === (string)$a['id']; ?>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($show_keys): ?>
                                    <i class="bi <?= (int)$a['is_correct']===1 ? 'bi-check-circle-fill ans-correct' : 'bi-x-circle-fill ans-wrong' ?>"></i>
                                <?php else: ?>
                                    <i class="bi bi-dot"></i>
                                <?php endif; ?>
                                <span><?= htmlspecialchars($a['content']) ?></span>
                                <?php if ($sel): ?><span class="badge bg-info text-dark">Ваш избор</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($q['qtype'] === 'multiple_choice'): ?>
                        <?php $sels = $studentAns && $studentAns['selected_option_ids'] ? array_map('intval', explode(',', $studentAns['selected_option_ids'])) : []; ?>
                        <?php foreach ($answers as $a): $sel = in_array((int)$a['id'], $sels, true); ?>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($show_keys): ?>
                                    <i class="bi <?= (int)$a['is_correct']===1 ? 'bi-check-circle-fill ans-correct' : 'bi-x-circle-fill ans-wrong' ?>"></i>
                                <?php else: ?>
                                    <i class="bi bi-dot"></i>
                                <?php endif; ?>
                                <span><?= htmlspecialchars($a['content']) ?></span>
                                <?php if ($sel): ?><span class="badge bg-info text-dark">Ваш избор</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($q['qtype'] === 'short_answer'): ?>
                        <div class="mb-2"><span class="text-muted">Ваш отговор:</span> <strong><?= htmlspecialchars($studentAns['free_text'] ?? '') ?></strong></div>
                        <?php if ($show_keys && $answers): ?>
                            <div class="text-muted small">Допустими: <?= htmlspecialchars(implode(', ', array_map(fn($x)=> $x['content'], $answers))) ?></div>
                        <?php endif; ?>
                    <?php elseif ($q['qtype'] === 'numeric'): ?>
                        <div class="mb-2"><span class="text-muted">Ваш отговор:</span> <strong><?= htmlspecialchars($studentAns['numeric_value'] ?? '') ?></strong></div>
                        <?php if ($show_keys && !empty($answers[0]['content'])): ?>
                            <div class="text-muted small">Верният отговор: <?= htmlspecialchars($answers[0]['content']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($show_keys && !empty($q['explanation'])): ?>
                        <div class="text-muted small mt-2">Обяснение: <?= htmlspecialchars($q['explanation']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</main>

<footer class="border-top py-4">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <div class="text-muted">&copy; <?= date('Y'); ?> TestGramatikov</div>
        <div class="d-flex gap-3 small">
            <a class="text-decoration-none" href="terms.php">Условия</a>
            <a class="text-decoration-none" href="privacy.php">Поверителност</a>
            <a class="text-decoration-none" href="contact.php">Контакт</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</footer>
</body>
</html>
