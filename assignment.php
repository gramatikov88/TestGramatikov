<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$pdo = db();
ensure_attempts_grade($pdo);
ensure_test_theme_and_q_media($pdo);
$student = $_SESSION['user'];

$assignment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($assignment_id <= 0) { http_response_code(400); die('Липсва задание.'); }

// Load assignment + test
$stmt = $pdo->prepare('SELECT a.*, t.id AS test_id, t.title AS test_title, t.time_limit_sec, t.is_randomized, t.is_strict_mode, t.theme, t.theme_config
                       FROM assignments a JOIN tests t ON t.id = a.test_id
                       WHERE a.id = :id');
$stmt->execute([':id' => $assignment_id]);
$assignment = $stmt->fetch();
if (!$assignment) { http_response_code(404); die('Заданието не е намерено.'); }
$strict_mode_active = !empty($assignment['is_strict_mode']);

// Access checks: published and within window
$now = time();
$window_ok = true;
if (!empty($assignment['open_at']) && strtotime($assignment['open_at']) > $now) $window_ok = false;
if (!empty($assignment['close_at']) && strtotime($assignment['close_at']) < $now) $window_ok = false;
if (!$assignment['is_published'] || !$window_ok) { http_response_code(403); die('Заданието не е активно.'); }

// Targeting: direct student or via class membership
$ok = false;
$stmt = $pdo->prepare('SELECT 1 FROM assignment_students WHERE assignment_id = :aid AND student_id = :sid LIMIT 1');
$stmt->execute([':aid'=>$assignment_id, ':sid'=>$student['id']]);
$ok = (bool)$stmt->fetchColumn();
if (!$ok) {
    $stmt = $pdo->prepare('SELECT 1 FROM assignment_classes ac JOIN class_students cs ON cs.class_id = ac.class_id
                           WHERE ac.assignment_id = :aid AND cs.student_id = :sid LIMIT 1');
    $stmt->execute([':aid'=>$assignment_id, ':sid'=>$student['id']]);
    $ok = (bool)$stmt->fetchColumn();
}
if (!$ok) { http_response_code(403); die('Нямате достъп до това задание.'); }

// Attempts info
$stmt = $pdo->prepare('SELECT COUNT(*) FROM attempts WHERE assignment_id = :aid AND student_id = :sid AND status <> "in_progress"');
$stmt->execute([':aid'=>$assignment_id, ':sid'=>$student['id']]);
$completed_attempts = (int)$stmt->fetchColumn();
$attempt_limit = (int)$assignment['attempt_limit'];
$can_attempt = ($attempt_limit === 0) || ($completed_attempts < $attempt_limit);
$activeAttemptStmt = $pdo->prepare('SELECT * FROM attempts WHERE assignment_id = :aid AND student_id = :sid AND status = "in_progress" ORDER BY started_at DESC LIMIT 1');
$activeAttemptStmt->execute([':aid'=>$assignment_id, ':sid'=>$student['id']]);
$activeAttempt = $activeAttemptStmt->fetch();
$activeAttemptId = $activeAttempt ? (int)$activeAttempt['id'] : null;
$activeAttemptNo = $activeAttempt ? (int)$activeAttempt['attempt_no'] : ($completed_attempts + 1);
$attempt_context = [
    'attempt_id' => $activeAttemptId,
    'attempt_no' => $activeAttemptNo,
];
$justCreatedAttempt = false;
if (!$activeAttempt && $can_attempt) {
    $attempt_no = $completed_attempts + 1;
    $ins = $pdo->prepare('INSERT INTO attempts (assignment_id, test_id, student_id, attempt_no, status, started_at) VALUES (:aid,:tid,:sid,:no,"in_progress", NOW())');
    $ins->execute([':aid'=>$assignment_id, ':tid'=>$assignment['test_id'], ':sid'=>$student['id'], ':no'=>$attempt_no]);
    $activeAttemptId = (int)$pdo->lastInsertId();
    $activeAttempt = [
        'id' => $activeAttemptId,
        'assignment_id' => $assignment_id,
        'test_id' => $assignment['test_id'],
        'student_id' => $student['id'],
        'attempt_no' => $attempt_no,
    ];
    $attempt_context['attempt_id'] = $activeAttemptId;
    $attempt_context['attempt_no'] = $attempt_no;
    $justCreatedAttempt = true;
    try {
        log_test_event($pdo, [
            'attempt_id' => $activeAttemptId,
            'assignment_id' => $assignment_id,
            'test_id' => (int)$assignment['test_id'],
            'student_id' => (int)$student['id'],
            'action' => 'test_start',
            'meta' => ['attempt_no' => $attempt_no],
        ]);
    } catch (Throwable $e) {
        // ignore logging errors
    }
    try {
        foreach ($questions as $q) {
            log_test_event($pdo, [
                'attempt_id' => $activeAttemptId,
                'assignment_id' => $assignment_id,
                'test_id' => (int)$assignment['test_id'],
                'student_id' => (int)$student['id'],
                'question_id' => (int)$q['id'],
                'action' => 'question_show',
            ]);
        }
    } catch (Throwable $e) {
        // ignore
    }
} elseif ($activeAttempt) {
    try {
        log_test_event($pdo, [
            'attempt_id' => $activeAttemptId,
            'assignment_id' => $assignment_id,
            'test_id' => (int)$assignment['test_id'],
            'student_id' => (int)$student['id'],
            'action' => 'test_resume',
            'meta' => ['attempt_no' => (int)$activeAttempt['attempt_no']],
        ]);
    } catch (Throwable $e) {
        // ignore
    }
}
$prev_attempts = $activeAttempt ? (int)$activeAttempt['attempt_no'] : ($can_attempt ? ($completed_attempts + 1) : $completed_attempts);

// Load test questions and answers
$stmt = $pdo->prepare('SELECT qb.*, tq.points, tq.order_index
                       FROM test_questions tq JOIN question_bank qb ON qb.id = tq.question_id
                       WHERE tq.test_id = :tid
                       ORDER BY tq.order_index');
$stmt->execute([':tid' => (int)$assignment['test_id']]);
$questions = $stmt->fetchAll();

if ($questions) {
    $ids = array_map(fn($r)=> (int)$r['id'], $questions);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ansStmt = $pdo->prepare("SELECT * FROM answers WHERE question_id IN ($in) ORDER BY order_index");
    $ansStmt->execute($ids);
    $byQ = [];
    while ($a = $ansStmt->fetch()) { $byQ[(int)$a['question_id']][] = $a; }
foreach ($questions as &$q) { $q['answers'] = $byQ[(int)$q['id']] ?? []; }
unset($q); // avoid lingering reference issues before shuffle/loops
}

// Shuffle questions if set on assignment
if (!empty($assignment['shuffle_questions'])) {
    // Reindex and shuffle to avoid duplicate rendering with lingering references
    $questions = array_values($questions);
    shuffle($questions);
}

$result = null; $error_msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAttemptId = isset($_POST['attempt_id']) ? (int) $_POST['attempt_id'] : 0;
    $attemptRow = null;
    if ($formAttemptId > 0) {
        $check = $pdo->prepare('SELECT * FROM attempts WHERE id = :id AND assignment_id = :aid AND student_id = :sid AND status = "in_progress" LIMIT 1');
        $check->execute([':id' => $formAttemptId, ':aid' => $assignment_id, ':sid' => $student['id']]);
        $attemptRow = $check->fetch();
    }
    if (!$attemptRow) {
        $error_msg = 'Опитът е невалиден или вече е приключен.';
    } else {
        $strict_violation = $strict_mode_active && (($_POST['strict_flag'] ?? '') === '1');
        try {
            $pdo->beginTransaction();
            $attempt_id = (int)$attemptRow['id'];
            if ($strict_violation) {
                $max = 0.0;
                foreach ($questions as $q) {
                    $max += (float)$q['points'];
                }
                $pdo->prepare('UPDATE attempts SET status = "submitted", submitted_at = NOW(), duration_sec = NULL, score_obtained = 0, max_score = :m, teacher_grade = 2, strict_violation = 1 WHERE id = :id')
                    ->execute([':m'=>$max, ':id'=>$attempt_id]);
                $pdo->commit();
                $completed_attempts++;
                $prev_attempts = $completed_attempts;
                $can_attempt = ($attempt_limit === 0) || ($completed_attempts < $attempt_limit);
                $activeAttempt = null;
                $activeAttemptId = null;
                $error_msg = 'Работата беше анулирана, защото напуснахте прозореца при строг режим. По правилата оценката е 2.';
                try {
                    log_test_event($pdo, [
                        'attempt_id' => $attempt_id,
                        'assignment_id' => $assignment_id,
                        'test_id' => (int)$assignment['test_id'],
                        'student_id' => (int)$student['id'],
                        'action' => 'test_submit',
                        'meta' => ['strict_violation' => true, 'score' => 0, 'max' => $max],
                    ]);
                } catch (Throwable $e) {
                    // ignore
                }
            } else {
                $score = 0.0; $max = 0.0;
                foreach ($questions as $q) {
                    $qid = (int)$q['id'];
                    $points = (float)$q['points'];
                    $max += $points;
                    $ansSel = $_POST['q_'.$qid] ?? null;
                    $is_correct = null; $award = 0.0; $ft = null; $num = null; $selIds = null;
                    $timeSpent = isset($_POST['qs_time'][$qid]) ? max(0, (float) $_POST['qs_time'][$qid]) : 0;
                    if (in_array($q['qtype'], ['single_choice','true_false'], true)) {
                        $selected = (int)($ansSel ?? 0);
                        if ($selected > 0) {
                            $selIds = (string)$selected;
                            $chk = $pdo->prepare('SELECT is_correct FROM answers WHERE id = :id AND question_id = :qid');
                            $chk->execute([':id'=>$selected, ':qid'=>$qid]);
                            $c = $chk->fetchColumn();
                            $is_correct = ($c !== false && (int)$c === 1) ? 1 : 0;
                            $award = $is_correct ? $points : 0.0;
                        } else { $is_correct = 0; }
                    } elseif ($q['qtype'] === 'multiple_choice') {
                        $selected = isset($_POST['q_'.$qid]) && is_array($_POST['q_'.$qid]) ? array_map('intval', $_POST['q_'.$qid]) : [];
                        sort($selected);
                        $selIds = implode(',', $selected);
                        $getCorr = $pdo->prepare('SELECT id FROM answers WHERE question_id = :qid AND is_correct = 1');
                        $getCorr->execute([':qid'=>$qid]);
                        $correct = array_map('intval', $getCorr->fetchAll(PDO::FETCH_COLUMN));
                        sort($correct);
                        $is_correct = ($selected === $correct) ? 1 : 0;
                        $award = $is_correct ? $points : 0.0;
                    } elseif ($q['qtype'] === 'short_answer') {
                        $ft = trim((string)($ansSel ?? ''));
                        if ($ft !== '') {
                            $getAcc = $pdo->prepare('SELECT content FROM answers WHERE question_id = :qid');
                            $getAcc->execute([':qid'=>$qid]);
                            $accepted = $getAcc->fetchAll(PDO::FETCH_COLUMN);
                            $norm = mb_strtolower(trim($ft));
                            $is_correct = in_array($norm, [mb_strtolower(trim(a)) for a in $accepted], true) ? 1 : 0;
                            $award = $is_correct ? $points : 0.0;
                        } else { $is_correct = 0; }
                    } elseif ($q['qtype'] === 'numeric') {
                        $num = ($ansSel !== null && $ansSel !== '') ? (float)$ansSel : null;
                        if ($num !== null) {
                            $getNum = $pdo->prepare('SELECT content FROM answers WHERE question_id = :qid LIMIT 1');
                            $getNum->execute([':qid'=>$qid]);
                            $corr = $getNum->fetchColumn();
                            $is_correct = ((float)$corr == (float)$num) ? 1 : 0;
                            $award = $is_correct ? $points : 0.0;
                        } else { $is_correct = 0; }
                    }
                    $score += $award;
                    $pdo->prepare('INSERT INTO attempt_answers (attempt_id, question_id, selected_option_ids, free_text, numeric_value, is_correct, score_awarded, time_spent_sec) VALUES (:att,:qid,:sel,:ft,:num,:ok,:aw,:time_spent)')
                        ->execute([
                            ':att'=>$attempt_id,
                            ':qid'=>$qid,
                            ':sel'=>$selIds,
                            ':ft'=>$ft,
                            ':num'=>$num,
                            ':ok'=>$is_correct,
                            ':aw'=>$award,
                            ':time_spent' => $timeSpent > 0 ? (int) round($timeSpent) : null,
                        ]);
                    try {
                        log_test_event($pdo, [
                            'attempt_id' => $attempt_id,
                            'assignment_id' => $assignment_id,
                            'test_id' => (int)$assignment['test_id'],
                            'student_id' => (int)$student['id'],
                            'question_id' => $qid,
                            'action' => 'question_answer',
                            'meta' => [
                                'is_correct' => $is_correct,
                                'score_awarded' => $award,
                                'time_spent_sec' => $timeSpent,
                            ],
                        ]);
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
                $pdo->prepare('UPDATE attempts SET status = "submitted", submitted_at = NOW(), duration_sec = TIMESTAMPDIFF(SECOND, started_at, NOW()), score_obtained = :s, max_score = :m, strict_violation = :sv WHERE id = :id')
                    ->execute([':s'=>$score, ':m'=>$max, ':sv'=>$strict_violation ? 1 : 0, ':id'=>$attempt_id]);
                $pdo->commit();
                $result = ['score'=>$score, 'max'=>$max, 'percent' => $max>0? round($score/$max*100,2):0];
                $completed_attempts++;
                $prev_attempts = $completed_attempts;
                $can_attempt = ($attempt_limit === 0) || ($completed_attempts < $attempt_limit);
                $activeAttempt = null;
                $activeAttemptId = null;
                try {
                    log_test_event($pdo, [
                        'attempt_id' => $attempt_id,
                        'assignment_id' => $assignment_id,
                        'test_id' => (int)$assignment['test_id'],
                        'student_id' => (int)$student['id'],
                        'action' => 'test_submit',
                        'meta' => [
                            'score' => $score,
                            'max' => $max,
                            'percent' => $result['percent'],
                            'strict_violation' => false,
                        ],
                    ]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = 'Възникна грешка: ' . $e->getMessage();
        }
    }
}



?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Задание – <?= htmlspecialchars($assignment['title']) ?> – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .q-card { border-left: 4px solid #0d6efd; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>
<?php $testTheme = $assignment['theme'] ?? 'default'; $testThemeConfig = $assignment['theme_config'] ?? null; include __DIR__ . '/components/test_styles.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h5 m-0">Задание: <?= htmlspecialchars($assignment['title']) ?></h1>
            <div class="text-muted">Тест: <?= htmlspecialchars($assignment['test_title']) ?></div>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Табло</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <div class="alert alert-info py-2 mb-0">
                <?php if (!empty($assignment['due_at'])): ?>
                    Срок: <strong><?= htmlspecialchars($assignment['due_at']) ?></strong>
                <?php else: ?>
                    Без крайна дата
                <?php endif; ?>
                <?php if ((int)$assignment['attempt_limit'] > 0): ?>
                    <span class="ms-3">Опит: <strong><?= $prev_attempts ?></strong> от <strong><?= (int)$assignment['attempt_limit'] ?></strong></span>
                <?php else: ?>
                    <span class="ms-3">Опити: <strong>неограничени</strong></span>
                <?php endif; ?>
                <?php if (!empty($assignment['shuffle_questions'])): ?>
                    <span class="ms-3">Въпроси: разбъркани</span>
                <?php endif; ?>
                <?php if (!empty($assignment['time_limit_sec'])): ?>
                    <span class="ms-3">Лимит: <?= (int)$assignment['time_limit_sec'] ?> сек.</span>
                <?php endif; ?>
                <?php if ($strict_mode_active): ?>
                    <span class="ms-3 text-danger fw-semibold">Стриктен режим: напускане на страницата анулира опита</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4 text-md-end">
            <?php if (!$can_attempt): ?>
                <div class="alert alert-warning py-2 mb-0">Нямате повече опити.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($result): ?>
        <div class="alert alert-success">Резултат: <strong><?= (float)$result['score'] ?>/<?= (float)$result['max'] ?></strong> (<?= (float)$result['percent'] ?>%)</div>
    <?php elseif ($error_msg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="strict_flag" id="strictFlag" value="0" />
        <?php if ($strict_mode_active): ?>
            <div id="strictModeNotice" class="alert alert-danger d-none">Нарушихте строгия режим. Опитът ще бъде анулиран и оценката се фиксира на 2.</div>
        <?php endif; ?>
        <?php foreach ($questions as $idx => $q): ?>
            <div class="card shadow-sm mb-3 q-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><strong>Въпрос <?= $idx+1 ?>.</strong> <?= nl2br(htmlspecialchars($q['body'])) ?></div>
                        <span class="badge bg-light text-dark"><?= (float)$q['points'] ?> т.</span>
                    </div>
                    <?php if (!empty($q['media_url'])): ?>
                        <div class="question-media mb-3">
                            <img src="<?= htmlspecialchars($q['media_url']) ?>" alt="Media" class="img-fluid rounded border">
                        </div>
                    <?php endif; ?>
                    <div class="mt-2">
                        <?php if (in_array($q['qtype'], ['single_choice','true_false'], true)): ?>
                            <?php foreach ($q['answers'] as $a): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="q_<?= (int)$q['id'] ?>" value="<?= (int)$a['id'] ?>" id="a<?= (int)$a['id'] ?>" />
                                    <label class="form-check-label" for="a<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['content']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($q['qtype'] === 'multiple_choice'): ?>
                            <?php foreach ($q['answers'] as $a): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="q_<?= (int)$q['id'] ?>[]" value="<?= (int)$a['id'] ?>" id="a<?= (int)$a['id'] ?>" />
                                    <label class="form-check-label" for="a<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['content']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($q['qtype'] === 'short_answer'): ?>
                            <input type="text" name="q_<?= (int)$q['id'] ?>" class="form-control" placeholder="Вашият отговор" />
                        <?php elseif ($q['qtype'] === 'numeric'): ?>
                            <input type="number" step="any" name="q_<?= (int)$q['id'] ?>" class="form-control" placeholder="Число" />
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" <?= $can_attempt ? '' : 'disabled' ?>><i class="bi bi-check2-circle me-1"></i>Предай</button>
        </div>
    </form>
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
<?php if ($strict_mode_active): ?>
<script>
(function(){
    const form = document.querySelector("form");
    if (!form) return;
    const flagInput = document.getElementById("strictFlag");
    const notice = document.getElementById("strictModeNotice");
    let triggered = false;
    function triggerViolation(){
        if (triggered) return;
        triggered = true;
        if (flagInput) { flagInput.value = "1"; }
        if (notice) { notice.classList.remove("d-none"); }
        const controls = form.querySelectorAll("input, select, textarea, button");
        controls.forEach(function(el){
            if (el === flagInput) return;
            el.disabled = true;
        });
        setTimeout(function(){
            try { form.submit(); } catch (e) {}
        }, 100);
    }
    document.addEventListener("visibilitychange", function(){
        if (document.hidden) { triggerViolation(); }
    });
    window.addEventListener("blur", function(){
        triggerViolation();
    });
})();
</script>
<?php endif; ?>
</footer>
</body>
</html>
