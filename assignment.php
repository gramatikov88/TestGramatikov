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

$assignment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($assignment_id <= 0) { http_response_code(400); die('Липсва задание.'); }

// Load assignment + test
$stmt = $pdo->prepare('SELECT a.*, t.id AS test_id, t.title AS test_title, t.time_limit_sec, t.is_randomized, t.theme, t.theme_config
                       FROM assignments a JOIN tests t ON t.id = a.test_id
                       WHERE a.id = :id');
$stmt->execute([':id' => $assignment_id]);
$assignment = $stmt->fetch();
if (!$assignment) { http_response_code(404); die('Заданието не е намерено.'); }

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
        $error_msg = 'Достигнат е лимитът на опитите.';
    } else {
        try {
            $pdo->beginTransaction();
            $attempt_no = $prev_attempts + 1;
            $ins = $pdo->prepare('INSERT INTO attempts (assignment_id, test_id, student_id, attempt_no, status, started_at) VALUES (:aid,:tid,:sid,:no, "in_progress", NOW())');
            $ins->execute([':aid'=>$assignment_id, ':tid'=>$assignment['test_id'], ':sid'=>$student['id'], ':no'=>$attempt_no]);
            $attempt_id = (int)$pdo->lastInsertId();

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

            $pdo->prepare('UPDATE attempts SET status = "submitted", submitted_at = NOW(), duration_sec = NULL, score_obtained = :s, max_score = :m WHERE id = :id')
                ->execute([':s'=>$score, ':m'=>$max, ':id'=>$attempt_id]);
            $pdo->commit();
            $result = ['score'=>$score, 'max'=>$max, 'percent' => $max>0? round($score/$max*100,2):0];
            // Update attempt counters
            $prev_attempts++;
            $can_attempt = ($attempt_limit === 0) || ($prev_attempts < $attempt_limit);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = 'Грешка при предаване: ' . $e->getMessage();
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
        <?php foreach ($questions as $idx => $q): ?>
            <div class="card shadow-sm mb-3 q-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><strong>Въпрос <?= $idx+1 ?>.</strong> <?= nl2br(htmlspecialchars($q['body'])) ?></div>
                        <span class="badge bg-light text-dark"><?= (float)$q['points'] ?> т.</span>
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
        <div class="text-muted">© <?= date('Y'); ?> TestGramatikov</div>
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
