<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = db();
$user = $_SESSION['user'] ?? null;

$test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
$mode = 'preview'; // preview or take

$test = null; $assignment = null; $questions = [];
function fetch_test_with_items(PDO $pdo, int $test_id): array {
    $stmt = $pdo->prepare('SELECT * FROM tests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $test_id]);
    $test = $stmt->fetch();
    if (!$test) return [null, []];
    $stmt = $pdo->prepare('SELECT qb.*, tq.points, tq.order_index FROM test_questions tq JOIN question_bank qb ON qb.id = tq.question_id WHERE tq.test_id = :tid ORDER BY tq.order_index');
    $stmt->execute([':tid' => $test_id]);
    $items = $stmt->fetchAll();
    if ($items) {
        $in = implode(',', array_fill(0, count($items), '?'));
        $ids = array_map(fn($r)=> (int)$r['id'], $items);
        $stmt = $pdo->prepare("SELECT * FROM answers WHERE question_id IN ($in) ORDER BY order_index");
        $stmt->execute($ids);
        $ans = $stmt->fetchAll();
        $byQ = [];
        foreach ($ans as $a) { $byQ[(int)$a['question_id']][] = $a; }
        foreach ($items as &$i) { $i['answers'] = $byQ[(int)$i['id']] ?? []; }
        unset($i); // drop reference before returning
    }
    return [$test, $items];
}

// Determine context
if ($assignment_id > 0) {
    $mode = 'take';
    $stmt = $pdo->prepare('SELECT a.*, t.title AS test_title, t.id AS test_id FROM assignments a JOIN tests t ON t.id = a.test_id WHERE a.id = :id LIMIT 1');
    $stmt->execute([':id' => $assignment_id]);
    $assignment = $stmt->fetch();
    if (!$assignment) { http_response_code(404); die('Assignment not found'); }
    $test_id = (int)$assignment['test_id'];
}

// Load test + questions
[$test, $questions] = fetch_test_with_items($pdo, $test_id);
if (!$test) { http_response_code(404); die('Test not found'); }

// Access control
if ($mode === 'preview') {
    // Teachers can preview own or shared tests; students can only preview shared
    if ($user) {
        if ($user['role'] === 'teacher') {
            if ((int)$test['owner_teacher_id'] !== (int)$user['id'] && $test['visibility'] !== 'shared') {
                http_response_code(403); die('Нямате достъп до този тест.');
            }
        } else {
            if ($test['visibility'] !== 'shared') { http_response_code(403); die('Нямате достъп.'); }
        }
    } else {
        if ($test['visibility'] !== 'shared') { header('Location: login.php'); exit; }
    }
} else {
    // Take mode requires logged in student and assignment target checks
    if (!$user || $user['role'] !== 'student') { header('Location: login.php'); exit; }
    // Check assignment is published and window is open
    $nowOk = true;
    if (!empty($assignment['open_at']) && strtotime($assignment['open_at']) > time()) $nowOk = false;
    if (!empty($assignment['close_at']) && strtotime($assignment['close_at']) < time()) $nowOk = false;
    if (!$assignment['is_published'] || !$nowOk) { http_response_code(403); die('Заданието не е активно.'); }
    // Check targeting
    $stmt = $pdo->prepare('SELECT 1 FROM assignment_students WHERE assignment_id = :aid AND student_id = :sid LIMIT 1');
    $stmt->execute([':aid'=>$assignment_id, ':sid'=>$user['id']]);
    $ok = (bool)$stmt->fetchColumn();
    if (!$ok) {
        $stmt = $pdo->prepare('SELECT 1 FROM assignment_classes ac JOIN class_students cs ON cs.class_id = ac.class_id WHERE ac.assignment_id = :aid AND cs.student_id = :sid LIMIT 1');
        $stmt->execute([':aid'=>$assignment_id, ':sid'=>$user['id']]);
        $ok = (bool)$stmt->fetchColumn();
    }
    if (!$ok) { http_response_code(403); die('Заданието не е предназначено за вас.'); }
}

$result = null;

if ($mode === 'take' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle submission
    try {
        $pdo->beginTransaction();
        // Enforce attempt limit
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(attempt_no),0) FROM attempts WHERE assignment_id = :aid AND student_id = :sid');
        $stmt->execute([':aid'=>$assignment_id, ':sid'=>$user['id']]);
        $prev = (int)$stmt->fetchColumn();
        $attempt_no = $prev + 1;
        if ((int)$assignment['attempt_limit'] > 0 && $attempt_no > (int)$assignment['attempt_limit']) {
            throw new RuntimeException('Достигнат е лимитът на опитите.');
        }

        $ins = $pdo->prepare('INSERT INTO attempts (assignment_id, test_id, student_id, attempt_no, status, started_at) VALUES (:aid,:tid,:sid,:no, "in_progress", NOW())');
        $ins->execute([':aid'=>$assignment_id, ':tid'=>$test_id, ':sid'=>$user['id'], ':no'=>$attempt_no]);
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
                    $stmt = $pdo->prepare('SELECT is_correct FROM answers WHERE id = :id AND question_id = :qid');
                    $stmt->execute([':id'=>$selected, ':qid'=>$qid]);
                    $c = $stmt->fetchColumn();
                    $is_correct = ($c !== false && (int)$c === 1) ? 1 : 0;
                    $award = $is_correct ? $points : 0.0;
                } else { $is_correct = 0; }
            } elseif ($q['qtype'] === 'multiple_choice') {
                $selected = isset($_POST['q_'.$qid]) && is_array($_POST['q_'.$qid]) ? array_map('intval', $_POST['q_'.$qid]) : [];
                sort($selected);
                $selIds = implode(',', $selected);
                // Fetch correct ids
                $stmt = $pdo->prepare('SELECT id FROM answers WHERE question_id = :qid AND is_correct = 1');
                $stmt->execute([':qid'=>$qid]);
                $correct = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                sort($correct);
                $is_correct = ($selected === $correct) ? 1 : 0;
                $award = $is_correct ? $points : 0.0;
            } elseif ($q['qtype'] === 'short_answer') {
                $ft = trim((string)($ansSel ?? ''));
                if ($ft !== '') {
                    $stmt = $pdo->prepare('SELECT content FROM answers WHERE question_id = :qid');
                    $stmt->execute([':qid'=>$qid]);
                    $accepted = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $norm = mb_strtolower(trim($ft));
                    $is_correct = in_array($norm, array_map(fn($a)=> mb_strtolower(trim($a)), $accepted), true) ? 1 : 0;
                    $award = $is_correct ? $points : 0.0;
                } else { $is_correct = 0; }
            } elseif ($q['qtype'] === 'numeric') {
                $num = ($ansSel !== null && $ansSel !== '') ? (float)$ansSel : null;
                if ($num !== null) {
                    $stmt = $pdo->prepare('SELECT content FROM answers WHERE question_id = :qid LIMIT 1');
                    $stmt->execute([':qid'=>$qid]);
                    $corr = $stmt->fetchColumn();
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
        $result = ['score'=>$score, 'max'=>$max];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $result = ['error' => 'Грешка при предаване: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= $mode==='take' ? 'Изпълнение на тест' : 'Преглед на тест' ?> – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .q-card { border-left: 4px solid #0d6efd; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>
<?php /* removed per request: test-specific theming */ ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 m-0"><?= htmlspecialchars($test['title']) ?></h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
    </div>

    <?php if ($mode==='take' && $result): ?>
        <?php if (!empty($result['error'])): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($result['error']) ?></div>
        <?php else: ?>
            <div class="alert alert-success">Резултат: <strong><?= (float)$result['score'] ?>/<?= (float)$result['max'] ?></strong> (<?= $result['max']>0? round($result['score']/$result['max']*100,2):0 ?>%)</div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($mode==='take' && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div class="alert alert-info">Задание: <?= htmlspecialchars($assignment['title'] ?? '') ?><?php if (!empty($assignment['due_at'])): ?> • Срок: <?= htmlspecialchars($assignment['due_at']) ?><?php endif; ?></div>
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
                                    <input class="form-check-input" type="radio" name="q_<?= (int)$q['id'] ?>" value="<?= (int)$a['id'] ?>" id="a<?= (int)$a['id'] ?>" <?= $mode==='preview'?'disabled':'' ?> />
                                    <label class="form-check-label" for="a<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['content']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($q['qtype'] === 'multiple_choice'): ?>
                            <?php foreach ($q['answers'] as $a): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="q_<?= (int)$q['id'] ?>[]" value="<?= (int)$a['id'] ?>" id="a<?= (int)$a['id'] ?>" <?= $mode==='preview'?'disabled':'' ?> />
                                    <label class="form-check-label" for="a<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['content']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($q['qtype'] === 'short_answer'): ?>
                            <input type="text" name="q_<?= (int)$q['id'] ?>" class="form-control" placeholder="Вашият отговор" <?= $mode==='preview'?'disabled':'' ?> />
                        <?php elseif ($q['qtype'] === 'numeric'): ?>
                            <input type="number" step="any" name="q_<?= (int)$q['id'] ?>" class="form-control" placeholder="Число" <?= $mode==='preview'?'disabled':'' ?> />
                        <?php endif; ?>
                    </div>
                    <?php if ($mode==='preview' && !empty($q['explanation'])): ?>
                        <div class="text-muted small mt-2">Обяснение: <?= htmlspecialchars($q['explanation']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($mode==='take'): ?>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Предай</button>
            </div>
        <?php else: ?>
            <div class="alert alert-secondary">Преглед на тест (без изпращане на отговори).</div>
        <?php endif; ?>
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