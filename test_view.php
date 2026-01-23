<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = db();
$user = $_SESSION['user'] ?? null;

$test_id = isset($_GET['test_id']) ? (int) $_GET['test_id'] : 0;
$assignment_id = isset($_GET['assignment_id']) ? (int) $_GET['assignment_id'] : 0;
$mode = 'preview'; // preview or take

$test = null;
$assignment = null;
$questions = [];
function fetch_test_with_items(PDO $pdo, int $test_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM tests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $test_id]);
    $test = $stmt->fetch();
    if (!$test)
        return [null, []];
    $stmt = $pdo->prepare('SELECT qb.*, tq.points, tq.order_index FROM test_questions tq JOIN question_bank qb ON qb.id = tq.question_id WHERE tq.test_id = :tid ORDER BY tq.order_index');
    $stmt->execute([':tid' => $test_id]);
    $items = $stmt->fetchAll();
    if ($items) {
        $in = implode(',', array_fill(0, count($items), '?'));
        $ids = array_map(fn($r) => (int) $r['id'], $items);
        $stmt = $pdo->prepare("SELECT * FROM answers WHERE question_id IN ($in) ORDER BY order_index");
        $stmt->execute($ids);
        $ans = $stmt->fetchAll();
        $byQ = [];
        foreach ($ans as $a) {
            $byQ[(int) $a['question_id']][] = $a;
        }
        foreach ($items as &$i) {
            $i['answers'] = $byQ[(int) $i['id']] ?? [];
        }
        unset($i);
    }
    return [$test, $items];
}

// Determine context
if ($assignment_id > 0) {
    $mode = 'take';
    $stmt = $pdo->prepare('SELECT a.*, t.title AS test_title, t.id AS test_id FROM assignments a JOIN tests t ON t.id = a.test_id WHERE a.id = :id LIMIT 1');
    $stmt->execute([':id' => $assignment_id]);
    $assignment = $stmt->fetch();
    if (!$assignment) {
        http_response_code(404);
        die('Assignment not found');
    }
    $test_id = (int) $assignment['test_id'];
}

// Load test + questions
[$test, $questions] = fetch_test_with_items($pdo, $test_id);
if (!$test) {
    http_response_code(404);
    die('Test not found');
}

// Access control
if ($mode === 'preview') {
    if ($user) {
        if ($user['role'] === 'teacher') {
            if ((int) $test['owner_teacher_id'] !== (int) $user['id'] && $test['visibility'] !== 'shared') {
                http_response_code(403);
                die('Нямате достъп до този тест.');
            }
        } else {
            if ($test['visibility'] !== 'shared') {
                http_response_code(403);
                die('Нямате достъп.');
            }
        }
    } else {
        if ($test['visibility'] !== 'shared') {
            header('Location: login.php');
            exit;
        }
    }
} else {
    if (!$user || $user['role'] !== 'student') {
        header('Location: login.php');
        exit;
    }
    // Check Assignment active
    $nowOk = true;
    if (!empty($assignment['open_at']) && strtotime($assignment['open_at']) > time())
        $nowOk = false;
    if (!empty($assignment['close_at']) && strtotime($assignment['close_at']) < time())
        $nowOk = false;
    if (!$assignment['is_published'] || !$nowOk) {
        http_response_code(403);
        die('Заданието не е активно.');
    }

    // Check targeting
    $stmt = $pdo->prepare('SELECT 1 FROM assignment_students WHERE assignment_id = :aid AND student_id = :sid LIMIT 1');
    $stmt->execute([':aid' => $assignment_id, ':sid' => $user['id']]);
    $ok = (bool) $stmt->fetchColumn();
    if (!$ok) {
        $stmt = $pdo->prepare('SELECT 1 FROM assignment_classes ac JOIN class_students cs ON cs.class_id = ac.class_id WHERE ac.assignment_id = :aid AND cs.student_id = :sid LIMIT 1');
        $stmt->execute([':aid' => $assignment_id, ':sid' => $user['id']]);
        $ok = (bool) $stmt->fetchColumn();
    }
    if (!$ok) {
        http_response_code(403);
        die('Заданието не е предназначено за вас.');
    }
}

$result = null;

if ($mode === 'take' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle submission (same internal logic as before, just kept clean)
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(attempt_no),0) FROM attempts WHERE assignment_id = :aid AND student_id = :sid');
        $stmt->execute([':aid' => $assignment_id, ':sid' => $user['id']]);
        $prev = (int) $stmt->fetchColumn();
        $attempt_no = $prev + 1;
        if ((int) $assignment['attempt_limit'] > 0 && $attempt_no > (int) $assignment['attempt_limit']) {
            throw new RuntimeException('Достигнат е лимитът на опитите.');
        }

        $ins = $pdo->prepare('INSERT INTO attempts (assignment_id, test_id, student_id, attempt_no, status, started_at) VALUES (:aid,:tid,:sid,:no, "in_progress", :now)');
        $ins->execute([':aid' => $assignment_id, ':tid' => $test_id, ':sid' => $user['id'], ':no' => $attempt_no, ':now' => date('Y-m-d H:i:s')]);
        $attempt_id_actual = (int) $pdo->lastInsertId();

        $score = 0.0;
        $max = 0.0;
        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $points = (float) $q['points'];
            $max += $points;
            $ansSel = $_POST['q_' . $qid] ?? null;
            $is_correct = 0;
            $award = 0.0;
            $ft = null;
            $num = null;
            $selIds = null;

            if (in_array($q['qtype'], ['single_choice', 'true_false'], true)) {
                $selected = (int) ($ansSel ?? 0);
                if ($selected > 0) {
                    $selIds = (string) $selected;
                    $stmt = $pdo->prepare('SELECT is_correct FROM answers WHERE id = :id AND question_id = :qid');
                    $stmt->execute([':id' => $selected, ':qid' => $qid]);
                    $c = $stmt->fetchColumn();
                    $is_correct = ($c !== false && (int) $c === 1) ? 1 : 0;
                    $award = $is_correct ? $points : 0.0;
                }
            } elseif ($q['qtype'] === 'multiple_choice') {
                $selected = isset($_POST['q_' . $qid]) && is_array($_POST['q_' . $qid]) ? array_map('intval', $_POST['q_' . $qid]) : [];
                sort($selected);
                $selIds = implode(',', $selected);
                $stmt = $pdo->prepare('SELECT id FROM answers WHERE question_id = :qid AND is_correct = 1');
                $stmt->execute([':qid' => $qid]);
                $correct = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                sort($correct);
                $is_correct = ($selected === $correct) ? 1 : 0;
                $award = $is_correct ? $points : 0.0;
            } elseif ($q['qtype'] === 'short_answer') {
                $ft = trim((string) ($ansSel ?? ''));
                if ($ft !== '') {
                    $stmt = $pdo->prepare('SELECT content FROM answers WHERE question_id = :qid');
                    $stmt->execute([':qid' => $qid]);
                    $accepted = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $norm = mb_strtolower(trim($ft));
                    $is_correct = in_array($norm, array_map(fn($a) => mb_strtolower(trim($a)), $accepted), true) ? 1 : 0;
                    $award = $is_correct ? $points : 0.0;
                }
            } elseif ($q['qtype'] === 'numeric') {
                $num = ($ansSel !== null && $ansSel !== '') ? (float) $ansSel : null;
                if ($num !== null) {
                    $stmt = $pdo->prepare('SELECT content FROM answers WHERE question_id = :qid LIMIT 1');
                    $stmt->execute([':qid' => $qid]);
                    $corr = $stmt->fetchColumn();
                    $is_correct = ((float) $corr == (float) $num) ? 1 : 0;
                    $award = $is_correct ? $points : 0.0;
                }
            }

            $score += $award;
            $pdo->prepare('INSERT INTO attempt_answers (attempt_id, question_id, selected_option_ids, free_text, numeric_value, is_correct, score_awarded) VALUES (:att,:qid,:sel,:ft,:num,:ok,:aw)')
                ->execute([':att' => $attempt_id_actual, ':qid' => $qid, ':sel' => $selIds, ':ft' => $ft, ':num' => $num, ':ok' => $is_correct, ':aw' => $award]);
        }

        $pdo->prepare('UPDATE attempts SET status = "submitted", submitted_at = :now, duration_sec = NULL, score_obtained = :s, max_score = :m WHERE id = :id')
            ->execute([':s' => $score, ':m' => $max, ':id' => $attempt_id_actual, ':now' => date('Y-m-d H:i:s')]);
        $pdo->commit();
        $result = ['score' => $score, 'max' => $max];
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $result = ['error' => 'Грешка при предаване: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= $mode === 'take' ? 'Изпълнение' : 'Преглед' ?> – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= time() ?>">
</head>

<body class="bg-body">
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-4 my-md-5 animate-fade-up" style="max-width: 800px;">

        <!-- Header -->
        <div class="glass-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="h3 mb-0"><?= htmlspecialchars($test['title']) ?></h1>
                <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i
                        class="bi bi-x-lg me-1"></i>Отказ</a>
            </div>
            <?php if ($mode === 'take' && !empty($assignment['due_at'])): ?>
                <div class="mt-2 text-muted small"><i class="bi bi-hourglass-split me-1"></i> Краен срок:
                    <?= format_date($assignment['due_at']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Results (if submitted) -->
        <?php if ($mode === 'take' && $result): ?>
            <?php if (!empty($result['error'])): ?>
                <div class="alert alert-danger shadow-sm rounded-3">
                    <div class="d-flex gap-2">
                        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                        <div><?= htmlspecialchars($result['error']) ?></div>
                    </div>
                </div>
            <?php else:
                $pct = percent($result['score'], $result['max']);
                $gr = grade_from_percent($pct);
                $grColor = get_grade_color_class($gr);
                ?>
                <div
                    class="alert alert-success shadow-sm rounded-4 text-center py-5 glass-card border-success border-opacity-25 bg-success bg-opacity-10">
                    <div class="display-1 text-success mb-3"><i class="bi bi-check-circle-fill"></i></div>
                    <h2 class="alert-heading fw-bold">Тестът е предаден успешно!</h2>
                    <div class="fs-4 my-3">
                        Резултат: <strong><?= (float) $result['score'] ?> / <?= (float) $result['max'] ?></strong>
                    </div>
                    <div>
                        <span class="badge bg-<?= $grColor ?> fs-5 px-4 py-2 rounded-pill shadow-sm">Оценка: <?= $gr ?></span>
                    </div>
                    <hr class="mx-auto my-4 w-50 opacity-25">
                    <a href="dashboard.php" class="btn btn-primary px-5 rounded-pill shadow-sm">Към таблото</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Test Form -->
        <?php if ($mode !== 'take' || (!$result && $_SERVER['REQUEST_METHOD'] !== 'POST')): // Show form if not submitted ?>

            <?php if ($mode === 'take'): ?>
                <div class="card border-0 shadow-sm mb-4 bg-primary bg-opacity-10">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="flex-grow-1">
                            <small class="text-uppercase text-muted fw-bold tracking-wider">Скала за оценяване</small>
                            <div class="d-flex gap-2 mt-2 flex-wrap">
                                <span class="badge bg-success-subtle text-success border border-success-subtle">6
                                    (90-100%)</span>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">5
                                    (80-89%)</span>
                                <span class="badge bg-info-subtle text-info border border-info-subtle">4 (65-79%)</span>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">3
                                    (50-64%)</span>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">2 (<50%)< /span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" id="testForm">
                <?php foreach ($questions as $idx => $q): ?>
                    <div class="glass-card mb-4 position-relative">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h5 class="mb-0 fw-bold text-muted">Въпрос <?= $idx + 1 ?></h5>
                                <span class="badge bg-white text-secondary border shadow-sm"><?= (float) $q['points'] ?>
                                    т.</span>
                            </div>

                            <div class="fs-5 mb-4"><?= nl2br(htmlspecialchars($q['body'])) ?></div>

                            <?php if (!empty($q['media_url'])): ?>
                                <div class="mb-4">
                                    <img src="<?= htmlspecialchars($q['media_url']) ?>" class="img-fluid rounded-3 border shadow-sm"
                                        style="max-height: 400px;">
                                </div>
                            <?php endif; ?>

                            <div class="vstack gap-2">
                                <?php if (in_array($q['qtype'], ['single_choice', 'true_false'], true)): ?>
                                    <?php foreach ($q['answers'] as $a): ?>
                                        <label
                                            class="form-check p-3 rounded-3 border bg-white bg-opacity-50 hover-bg-light cursor-pointer transition-all position-relative">
                                            <input class="form-check-input mt-1 me-2" type="radio" name="q_<?= (int) $q['id'] ?>"
                                                value="<?= (int) $a['id'] ?>" <?= $mode === 'preview' ? 'disabled' : '' ?> />
                                            <span class="form-check-label stretched-link"><?= htmlspecialchars($a['content']) ?></span>
                                        </label>
                                    <?php endforeach; ?>

                                <?php elseif ($q['qtype'] === 'multiple_choice'): ?>
                                    <?php foreach ($q['answers'] as $a): ?>
                                        <label
                                            class="form-check p-3 rounded-3 border bg-white bg-opacity-50 hover-bg-light cursor-pointer transition-all position-relative">
                                            <input class="form-check-input mt-1 me-2" type="checkbox" name="q_<?= (int) $q['id'] ?>[]"
                                                value="<?= (int) $a['id'] ?>" <?= $mode === 'preview' ? 'disabled' : '' ?> />
                                            <span class="form-check-label stretched-link"><?= htmlspecialchars($a['content']) ?></span>
                                        </label>
                                    <?php endforeach; ?>

                                <?php elseif ($q['qtype'] === 'short_answer'): ?>
                                    <input type="text" name="q_<?= (int) $q['id'] ?>" class="form-control form-control-lg"
                                        placeholder="Въведете вашият отговор тук..." <?= $mode === 'preview' ? 'disabled' : '' ?> />

                                <?php elseif ($q['qtype'] === 'numeric'): ?>
                                    <input type="number" step="any" name="q_<?= (int) $q['id'] ?>"
                                        class="form-control form-control-lg" placeholder="Въведете число..."
                                        <?= $mode === 'preview' ? 'disabled' : '' ?> />
                                <?php endif; ?>
                            </div>

                            <?php if ($mode === 'preview' && !empty($q['explanation'])): ?>
                                <div class="alert alert-secondary mt-3 mb-0 small"><i class="bi bi-info-circle me-1"></i>
                                    <?= htmlspecialchars($q['explanation']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($mode === 'take'): ?>
                    <div class="d-grid mt-5">
                        <button type="submit" class="btn btn-primary btn-lg rounded-pill shadow-lg py-3 fw-bold"
                            onclick="return confirm('Сигурни ли сте, че искате да предадете теста?');">
                            <i class="bi bi-send-check-fill me-2"></i> Предай теста
                        </button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary text-center mt-4">
                        Това е режим на преглед. Вашите отговори няма да бъдат запазени.
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </main>

    <?php include __DIR__ . '/components/footer.php'; ?>
    <style>
        .hover-bg-light:hover {
            background-color: rgba(255, 255, 255, 0.9) !important;
            border-color: var(--tg-primary) !important;
        }

        .cursor-pointer {
            cursor: pointer;
        }
    </style>
</body>

</html>