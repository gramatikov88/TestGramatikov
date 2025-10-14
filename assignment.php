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
if ($assignment_id <= 0) { http_response_code(400); die('Ð›Ð¸Ð¿ÑÐ²Ð° Ð·Ð°Ð´Ð°Ð½Ð¸Ðµ.'); }

// Load assignment + test
$stmt = $pdo->prepare('SELECT a.*, t.id AS test_id, t.title AS test_title, t.time_limit_sec, t.is_randomized, t.is_strict_mode, t.theme, t.theme_config
                       FROM assignments a JOIN tests t ON t.id = a.test_id
                       WHERE a.id = :id');
$stmt->execute([':id' => $assignment_id]);
$assignment = $stmt->fetch();
if (!$assignment) { http_response_code(404); die('Ð—Ð°Ð´Ð°Ð½Ð¸ÐµÑ‚Ð¾ Ð½Ðµ Ðµ Ð½Ð°Ð¼ÐµÑ€ÐµÐ½Ð¾.'); }
$strict_mode_active = !empty($assignment['is_strict_mode']);

// Access checks: published and within window
$now = time();
$window_ok = true;
if (!empty($assignment['open_at']) && strtotime($assignment['open_at']) > $now) $window_ok = false;
if (!empty($assignment['close_at']) && strtotime($assignment['close_at']) < $now) $window_ok = false;
if (!$assignment['is_published'] || !$window_ok) { http_response_code(403); die('Ð—Ð°Ð´Ð°Ð½Ð¸ÐµÑ‚Ð¾ Ð½Ðµ Ðµ Ð°ÐºÑ‚Ð¸Ð²Ð½Ð¾.'); }

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
if (!$ok) { http_response_code(403); die('ÐÑÐ¼Ð°Ñ‚Ðµ Ð´Ð¾ÑÑ‚ÑŠÐ¿ Ð´Ð¾ Ñ‚Ð¾Ð²Ð° Ð·Ð°Ð´Ð°Ð½Ð¸Ðµ.'); }

// Attempts info
$stmt = $pdo->prepare('SELECT COALESCE(MAX(attempt_no),0) FROM attempts WHERE assignment_id = :aid AND student_id = :sid');
$stmt->execute([':aid'=>$assignment_id, ':sid'=>$student['id']]);
$prev_attempts = (int)$stmt->fetchColumn();
$attempt_limit = (int)$assignment['attempt_limit'];
$can_attempt = ($attempt_limit === 0) || ($prev_attempts < $attempt_limit);

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
    if (!$can_attempt) {
        $error_msg = 'Ð”Ð¾ÑÑ‚Ð¸Ð³Ð½Ð°Ñ‚ Ðµ Ð»Ð¸Ð¼Ð¸Ñ‚ÑŠÑ‚ Ð½Ð° Ð¾Ð¿Ð¸Ñ‚Ð¸Ñ‚Ðµ.';
    } else {
        $strict_violation = $strict_mode_active && (($_POST['strict_flag'] ?? '') === '1');
        try {
            $pdo->beginTransaction();
            $attempt_no = $prev_attempts + 1;
            $ins = $pdo->prepare('INSERT INTO attempts (assignment_id, test_id, student_id, attempt_no, status, started_at) VALUES (:aid,:tid,:sid,:no, "in_progress", NOW())');
            $ins->execute([':aid'=>$assignment_id, ':tid'=>$assignment['test_id'], ':sid'=>$student['id'], ':no'=>$attempt_no]);
            $attempt_id = (int)$pdo->lastInsertId();

            if ($strict_violation) {
                $max = 0.0;
                foreach ($questions as $q) {
                    $max += (float)$q['points'];
                }
                $pdo->prepare('UPDATE attempts SET status = "submitted", submitted_at = NOW(), duration_sec = NULL, score_obtained = 0, max_score = :m, teacher_grade = 2, strict_violation = 1 WHERE id = :id')
                    ->execute([':m'=>$max, ':id'=>$attempt_id]);
                $pdo->commit();
                $prev_attempts++;
                $can_attempt = ($attempt_limit === 0) || ($prev_attempts < $attempt_limit);
                $error_msg = 'Работата беше анулирана, защото напуснахте прозореца при строг режим. По правилата оценката е 2.';
            } else {
                $score = 0.0; $max = 0.0;
                foreach ($questions as $q) {
                    $qid = (int)$q['id'];
                    $points = (float)$q['points'];
                    $max += $points;
                    $ansSel = $_POST['q_'.$qid] ?? null;
                    $is_correct = null; $award = 0.0; $ft = null; $num = null; $selIds = null;

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
                            $is_correct = in_array($norm, array_map(fn($a)=> mb_strtolower(trim($a)), $accepted), true) ? 1 : 0;
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
                    $pdo->prepare('INSERT INTO attempt_answers (attempt_id, question_id, selected_option_ids, free_text, numeric_value, is_correct, score_awarded) VALUES (:att,:qid,:sel,:ft,:num,:ok,:aw)')
                        ->execute([
                            ':att'=>$attempt_id,
                            ':qid'=>$qid,
                            ':sel'=>$selIds,
                            ':ft'=>$ft,
                            ':num'=>$num,
                            ':ok'=>$is_correct,
                            ':aw'=>$award,
                        ]);
                }

                $pdo->prepare('UPDATE attempts SET status = "submitted", submitted_at = NOW(), duration_sec = NULL, score_obtained = :s, max_score = :m, strict_violation = 0 WHERE id = :id')
                    ->execute([':s'=>$score, ':m'=>$max, ':id'=>$attempt_id]);
                $pdo->commit();
                $result = ['score'=>$score, 'max'=>$max, 'percent' => $max>0? round($score/$max*100,2):0];
                $prev_attempts++;
                $can_attempt = ($attempt_limit === 0) || ($prev_attempts < $attempt_limit);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = 'Ð“Ñ€ÐµÑˆÐºÐ° Ð¿Ñ€Ð¸ Ð¿Ñ€ÐµÐ´Ð°Ð²Ð°Ð½Ðµ: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Ð—Ð°Ð´Ð°Ð½Ð¸Ðµ â€“ <?= htmlspecialchars($assignment['title']) ?> â€“ TestGramatikov</title>
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
            <h1 class="h5 m-0">Ð—Ð°Ð´Ð°Ð½Ð¸Ðµ: <?= htmlspecialchars($assignment['title']) ?></h1>
            <div class="text-muted">Ð¢ÐµÑÑ‚: <?= htmlspecialchars($assignment['test_title']) ?></div>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Ð¢Ð°Ð±Ð»Ð¾</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <div class="alert alert-info py-2 mb-0">
                <?php if (!empty($assignment['due_at'])): ?>
                    Ð¡Ñ€Ð¾Ðº: <strong><?= htmlspecialchars($assignment['due_at']) ?></strong>
                <?php else: ?>
                    Ð‘ÐµÐ· ÐºÑ€Ð°Ð¹Ð½Ð° Ð´Ð°Ñ‚Ð°
                <?php endif; ?>
                <?php if ((int)$assignment['attempt_limit'] > 0): ?>
                    <span class="ms-3">ÐžÐ¿Ð¸Ñ‚: <strong><?= $prev_attempts ?></strong> Ð¾Ñ‚ <strong><?= (int)$assignment['attempt_limit'] ?></strong></span>
                <?php else: ?>
                    <span class="ms-3">ÐžÐ¿Ð¸Ñ‚Ð¸: <strong>Ð½ÐµÐ¾Ð³Ñ€Ð°Ð½Ð¸Ñ‡ÐµÐ½Ð¸</strong></span>
                <?php endif; ?>
                <?php if (!empty($assignment['shuffle_questions'])): ?>
                    <span class="ms-3">Ð’ÑŠÐ¿Ñ€Ð¾ÑÐ¸: Ñ€Ð°Ð·Ð±ÑŠÑ€ÐºÐ°Ð½Ð¸</span>
                <?php endif; ?>
                <?php if (!empty($assignment['time_limit_sec'])): ?>
                    <span class="ms-3">Ð›Ð¸Ð¼Ð¸Ñ‚: <?= (int)$assignment['time_limit_sec'] ?> ÑÐµÐº.</span>
                <?php endif; ?>
                <?php if ($strict_mode_active): ?>
                    <span class="ms-3 text-danger fw-semibold">Стриктен режим: напускане на страницата анулира опита</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4 text-md-end">
            <?php if (!$can_attempt): ?>
                <div class="alert alert-warning py-2 mb-0">ÐÑÐ¼Ð°Ñ‚Ðµ Ð¿Ð¾Ð²ÐµÑ‡Ðµ Ð¾Ð¿Ð¸Ñ‚Ð¸.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($result): ?>
        <div class="alert alert-success">Ð ÐµÐ·ÑƒÐ»Ñ‚Ð°Ñ‚: <strong><?= (float)$result['score'] ?>/<?= (float)$result['max'] ?></strong> (<?= (float)$result['percent'] ?>%)</div>
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
                        <div><strong>Ð’ÑŠÐ¿Ñ€Ð¾Ñ <?= $idx+1 ?>.</strong> <?= nl2br(htmlspecialchars($q['body'])) ?></div>
                        <span class="badge bg-light text-dark"><?= (float)$q['points'] ?> Ñ‚.</span>
                    </div>
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
                            <input type="text" name="q_<?= (int)$q['id'] ?>" class="form-control" placeholder="Ð’Ð°ÑˆÐ¸ÑÑ‚ Ð¾Ñ‚Ð³Ð¾Ð²Ð¾Ñ€" />
                        <?php elseif ($q['qtype'] === 'numeric'): ?>
                            <input type="number" step="any" name="q_<?= (int)$q['id'] ?>" class="form-control" placeholder="Ð§Ð¸ÑÐ»Ð¾" />
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" <?= $can_attempt ? '' : 'disabled' ?>><i class="bi bi-check2-circle me-1"></i>ÐŸÑ€ÐµÐ´Ð°Ð¹</button>
        </div>
    </form>
</main>

<footer class="border-top py-4">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <div class="text-muted">Â© <?= date('Y'); ?> TestGramatikov</div>
        <div class="d-flex gap-3 small">
            <a class="text-decoration-none" href="terms.php">Ð£ÑÐ»Ð¾Ð²Ð¸Ñ</a>
            <a class="text-decoration-none" href="privacy.php">ÐŸÐ¾Ð²ÐµÑ€Ð¸Ñ‚ÐµÐ»Ð½Ð¾ÑÑ‚</a>
            <a class="text-decoration-none" href="contact.php">ÐšÐ¾Ð½Ñ‚Ð°ÐºÑ‚</a>
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
