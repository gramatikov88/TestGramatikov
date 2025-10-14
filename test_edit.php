<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$pdo = db();
ensure_test_theme_and_q_media($pdo);
ensure_subjects_scope($pdo);

$test_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($test_id <= 0) {
    header('Location: dashboard.php');
    exit;
}

// Load test and verify ownership
$stmt = $pdo->prepare('SELECT * FROM tests WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $test_id]);
$test = $stmt->fetch();
if (!$test || (int)$test['owner_teacher_id'] !== (int)$user['id']) {
    header('Location: dashboard.php');
    exit;
}

// Load subjects
$subjects = [];
try {
    $stmt = $pdo->prepare('SELECT id, name FROM subjects WHERE owner_teacher_id = :tid ORDER BY name');
    $stmt->execute([':tid'=>$user['id']]);
    $subjects = $stmt->fetchAll();
} catch (Throwable $e) { $subjects = []; }

$errors = [];
$saved = isset($_GET['saved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $subject_id = (int)($_POST['subject_id'] ?? 0) ?: null;
    if ($subject_id) {
        $chk = $pdo->prepare('SELECT id FROM subjects WHERE id = :sid AND owner_teacher_id = :tid');
        $chk->execute([':sid'=>$subject_id, ':tid'=>$user['id']]);
        if (!$chk->fetchColumn()) { $subject_id = null; }
    }
    $description = trim((string)($_POST['description'] ?? ''));
    $visibility = in_array(($_POST['visibility'] ?? 'private'), ['private','shared'], true) ? $_POST['visibility'] : 'private';
    $theme = $_POST['theme'] ?? ($test['theme'] ?? 'default');
    $status = in_array(($_POST['status'] ?? 'draft'), ['draft','published','archived'], true) ? $_POST['status'] : 'draft';
    $theme_cfg_json = ($theme === 'custom' && !empty($_POST['theme_cfg'])) ? json_encode($_POST['theme_cfg']) : null;
    $time_limit_sec = (isset($_POST['time_limit_sec']) && $_POST['time_limit_sec'] !== '') ? max(0, (int)$_POST['time_limit_sec']) : null;
    $is_randomized = !empty($_POST['is_randomized']) ? 1 : 0;
    $is_strict_mode = !empty($_POST['is_strict_mode']) ? 1 : 0;

    $questions = $_POST['questions'] ?? [];

    if ($title === '') { $errors[] = 'ÐœÐ¾Ð»Ñ, Ð²ÑŠÐ²ÐµÐ´ÐµÑ‚Ðµ Ð·Ð°Ð³Ð»Ð°Ð²Ð¸Ðµ Ð½Ð° Ñ‚ÐµÑÑ‚Ð°.'; }
    if (empty($questions)) { $errors[] = 'Ð”Ð¾Ð±Ð°Ð²ÐµÑ‚Ðµ Ð¿Ð¾Ð½Ðµ ÐµÐ´Ð¸Ð½ Ð²ÑŠÐ¿Ñ€Ð¾Ñ.'; }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Update test
            $stmt = $pdo->prepare('UPDATE tests SET subject_id=:subject_id, title=:title, description=:description, visibility=:visibility, status=:status, time_limit_sec=:time_limit_sec, is_randomized=:is_randomized, is_strict_mode=:is_strict_mode, theme=:theme, theme_config=:theme_config WHERE id=:id');
            $stmt->execute([
                ':subject_id' => $subject_id,
                ':title' => $title,
                ':description' => $description,
                ':visibility' => $visibility,
                ':status' => $status,
                ':time_limit_sec' => $time_limit_sec,
                ':is_randomized' => $is_randomized,
                ':is_strict_mode' => $is_strict_mode,
                ':theme' => $theme,
                ':theme_config' => $theme_cfg_json,
                ':id' => $test_id,
            ]);

            // Rebuild mapping
            $pdo->prepare('DELETE FROM test_questions WHERE test_id = :tid')->execute([':tid' => $test_id]);

            $order_index = 0;
            foreach ($questions as $q) {
                $order_index++;
                $existing_qid = isset($q['id']) && ctype_digit((string)$q['id']) ? (int)$q['id'] : 0;
                $qtype = $q['qtype'] ?? 'single_choice';
                if (!in_array($qtype, ['single_choice','multiple_choice','true_false','short_answer','numeric'], true)) {
                    $qtype = 'single_choice';
                }
                $body = trim((string)($q['body'] ?? ''));
                $explanation = trim((string)($q['explanation'] ?? '')) ?: null;
                $difficulty = ($q['difficulty'] ?? '') !== '' ? max(1, min(5, (int)$q['difficulty'])) : null;
                $points = ($q['points'] ?? '') !== '' ? (float)$q['points'] : 1.0;

                if ($body === '') { throw new RuntimeException('Ð›Ð¸Ð¿ÑÐ²Ð° ÑÑŠÐ´ÑŠÑ€Ð¶Ð°Ð½Ð¸Ðµ Ð½Ð° Ð²ÑŠÐ¿Ñ€Ð¾Ñ.'); }

                $question_id = 0;
                if ($existing_qid > 0) {
                    // Verify ownership on the question, otherwise duplicate
                    $qrow = $pdo->prepare('SELECT id, owner_teacher_id FROM question_bank WHERE id = :id');
                    $qrow->execute([':id' => $existing_qid]);
                    $qrow = $qrow->fetch();
                    if ($qrow && (int)$qrow['owner_teacher_id'] === (int)$user['id']) {
                        // Update in place
                        $stmt = $pdo->prepare('UPDATE question_bank SET visibility=:visibility, qtype=:qtype, body=:body, explanation=:explanation, difficulty=:difficulty WHERE id=:id');
                        $stmt->execute([
                            ':visibility' => $visibility,
                            ':qtype' => $qtype,
                            ':body' => $body,
                            ':explanation' => $explanation,
                            ':difficulty' => $difficulty,
                            ':id' => $existing_qid,
                        ]);
                        // Reset answers
                        $pdo->prepare('DELETE FROM answers WHERE question_id = :qid')->execute([':qid' => $existing_qid]);
                        $question_id = $existing_qid;
                    } else {
                        $existing_qid = 0; // will create new below
                    }
                }

                if ($existing_qid === 0) {
                    // Insert new question
                    $stmt = $pdo->prepare('INSERT INTO question_bank (owner_teacher_id, visibility, qtype, body, explanation, difficulty) VALUES (:owner,:visibility,:qtype,:body,:explanation,:difficulty)');
                    $stmt->execute([
                        ':owner' => (int)$user['id'],
                        ':visibility' => $visibility,
                        ':qtype' => $qtype,
                        ':body' => $body,
                        ':explanation' => $explanation,
                        ':difficulty' => $difficulty,
                    ]);
                    $question_id = (int)$pdo->lastInsertId();
                }

                // Handle optional media upload/removal aligned by position index
                $pos = $order_index - 1;
                if (isset($_POST['questions'][$pos]['remove_media']) && $_POST['questions'][$pos]['remove_media'] === '1') {
                    $pdo->prepare('UPDATE question_bank SET media_url = NULL, media_mime = NULL WHERE id = :id')->execute([':id'=>$question_id]);
                }
                if (!empty($_FILES['qfile']) && isset($_FILES['qfile']['error'][$pos]) && $_FILES['qfile']['error'][$pos] === UPLOAD_ERR_OK) {
                    $allowed = ['image/jpeg','image/png','image/gif','application/pdf'];
                    $mime = mime_content_type($_FILES['qfile']['tmp_name'][$pos]);
                    if (in_array($mime, $allowed, true)) {
                        $ext = pathinfo($_FILES['qfile']['name'][$pos], PATHINFO_EXTENSION);
                        $name = 'q_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $dir = __DIR__ . '/uploads'; if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                        if (move_uploaded_file($_FILES['qfile']['tmp_name'][$pos], $dir . '/' . $name)) {
                            $rel = 'uploads/' . $name;
                            $pdo->prepare('UPDATE question_bank SET media_url = :url, media_mime = :mime WHERE id = :id')->execute([':url'=>$rel, ':mime'=>$mime, ':id'=>$question_id]);
                        }
                    }
                }

                // Recreate answers
                if ($qtype === 'single_choice' || $qtype === 'multiple_choice' || $qtype === 'true_false') {
                    $opts = $q['options'] ?? [];
                    $hasCorrect = false;
                    $order = 0;
                    foreach ($opts as $opt) {
                        $content = trim((string)($opt['content'] ?? ''));
                        if ($content === '') { continue; }
                        $order++;
                        $is_correct = !empty($opt['is_correct']) ? 1 : 0;
                        if ($is_correct) { $hasCorrect = true; }
                        $stmt = $pdo->prepare('INSERT INTO answers (question_id, content, is_correct, order_index) VALUES (:qid,:content,:is_correct,:ord)');
                        $stmt->execute([':qid'=>$question_id, ':content'=>$content, ':is_correct'=>$is_correct, ':ord'=>$order]);
                    }
                    if (!$hasCorrect) {
                        throw new RuntimeException('ÐÑÐ¼Ð° Ð¼Ð°Ñ€ÐºÐ¸Ñ€Ð°Ð½ Ð²ÐµÑ€ÐµÐ½ Ð¾Ñ‚Ð³Ð¾Ð²Ð¾Ñ€ Ð¿Ñ€Ð¸ Ð·Ð°Ñ‚Ð²Ð¾Ñ€ÐµÐ½ Ð²ÑŠÐ¿Ñ€Ð¾Ñ.');
                    }
                } elseif ($qtype === 'short_answer') {
                    $answers_line = trim((string)($q['short_answers'] ?? ''));
                    if ($answers_line !== '') {
                        $accepted = array_filter(array_map('trim', preg_split('/\r?\n|\|/', $answers_line)));
                        $order = 0;
                        foreach ($accepted as $ans) {
                            $order++;
                            $stmt = $pdo->prepare('INSERT INTO answers (question_id, content, is_correct, order_index) VALUES (:qid,:content,1,:ord)');
                            $stmt->execute([':qid'=>$question_id, ':content'=>$ans, ':ord'=>$order]);
                        }
                    }
                } elseif ($qtype === 'numeric') {
                    $num = trim((string)($q['numeric_answer'] ?? ''));
                    if ($num !== '') {
                        $stmt = $pdo->prepare('INSERT INTO answers (question_id, content, is_correct, order_index) VALUES (:qid,:content,1,1)');
                        $stmt->execute([':qid'=>$question_id, ':content'=>$num]);
                    }
                }

                // Map into test
                $stmt = $pdo->prepare('INSERT INTO test_questions (test_id, question_id, points, order_index) VALUES (:test_id,:question_id,:points,:ord)');
                $stmt->execute([':test_id'=>$test_id, ':question_id'=>$question_id, ':points'=>$points, ':ord'=>$order_index]);
            }

            $pdo->commit();
            header('Location: test_edit.php?id='.$test_id.'&saved=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Ð“Ñ€ÐµÑˆÐºÐ° Ð¿Ñ€Ð¸ Ð·Ð°Ð¿Ð¸Ñ: ' . $e->getMessage();
        }
    }
}

// Load questions and answers for initial render
$stmt = $pdo->prepare('SELECT tq.points, tq.order_index, qb.*
                       FROM test_questions tq JOIN question_bank qb ON qb.id = tq.question_id
                       WHERE tq.test_id = :tid
                       ORDER BY tq.order_index ASC');
$stmt->execute([':tid' => $test_id]);
$qrows = $stmt->fetchAll();

$qids = array_map(fn($r)=> (int)$r['id'], $qrows);
$answersByQ = [];
if ($qids) {
    $in = implode(',', array_fill(0, count($qids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM answers WHERE question_id IN ($in) ORDER BY order_index");
    $stmt->execute($qids);
    while ($a = $stmt->fetch()) {
        $answersByQ[(int)$a['question_id']][] = $a;
    }
}

$existingQuestions = [];
foreach ($qrows as $q) {
    $qid = (int)$q['id'];
    $entry = [
        'id' => $qid,
        'qtype' => $q['qtype'],
        'body' => $q['body'],
        'explanation' => $q['explanation'],
        'difficulty' => $q['difficulty'],
        'points' => $q['points'],
        'options' => [],
        'short_answers' => '',
        'numeric_answer' => '',
    ];
    $ans = $answersByQ[$qid] ?? [];
    if (in_array($q['qtype'], ['single_choice','multiple_choice','true_false'], true)) {
        foreach ($ans as $op) {
            $entry['options'][] = [
                'content' => $op['content'],
                'is_correct' => (int)$op['is_correct'] === 1,
            ];
        }
    } elseif ($q['qtype'] === 'short_answer') {
        $entry['short_answers'] = implode("\n", array_map(fn($op)=> $op['content'], $ans));
    } elseif ($q['qtype'] === 'numeric') {
        $entry['numeric_answer'] = isset($ans[0]['content']) ? $ans[0]['content'] : '';
    }
    $existingQuestions[] = $entry;
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Ð ÐµÐ´Ð°ÐºÑ†Ð¸Ñ Ð½Ð° Ñ‚ÐµÑÑ‚ â€“ TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .q-card { border-left: 4px solid #0d6efd; }
        .option-row { gap: .5rem; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 m-0"><i class="bi bi-pencil-square me-2"></i>Ð ÐµÐ´Ð°ÐºÑ†Ð¸Ñ Ð½Ð° Ñ‚ÐµÑÑ‚</h1>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> ÐÐ°Ð·Ð°Ð´</a>
            <a href="createTest.php" class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> ÐÐ¾Ð² Ñ‚ÐµÑÑ‚</a>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="alert alert-success">ÐŸÑ€Ð¾Ð¼ÐµÐ½Ð¸Ñ‚Ðµ ÑÐ° Ð·Ð°Ð¿Ð°Ð·ÐµÐ½Ð¸.</div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="m-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" id="testForm" enctype="multipart/form-data">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Ð”Ð°Ð½Ð½Ð¸ Ð·Ð° Ñ‚ÐµÑÑ‚Ð°</strong></div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Ð—Ð°Ð³Ð»Ð°Ð²Ð¸Ðµ</label>
                    <input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($test['title']) ?>" />
                </div>
                <div class="col-md-3">
                    <label class="form-label">ÐŸÑ€ÐµÐ´Ð¼ÐµÑ‚</label>
                    <select name="subject_id" class="form-select">
                        <option value="">â€”</option>
                        <?php foreach ($subjects as $s): $sel = ($test['subject_id'] == $s['id']) ? 'selected' : ''; ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $sel ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ð’Ð¸Ð´Ð¸Ð¼Ð¾ÑÑ‚</label>
                    <select name="visibility" class="form-select">
                        <option value="private" <?= $test['visibility']==='private'?'selected':'' ?>>Ð¡Ð°Ð¼Ð¾ Ð°Ð·</option>
                        <option value="shared" <?= $test['visibility']==='shared'?'selected':'' ?>>Ð¡Ð¿Ð¾Ð´ÐµÐ»ÐµÐ½</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">ÐžÐ¿Ð¸ÑÐ°Ð½Ð¸Ðµ</label>
                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($test['description']) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ð¦Ð²ÐµÑ‚Ð¾Ð²Ð° ÑÑ…ÐµÐ¼Ð°</label>
                    <select name="theme" class="form-select">
                        <?php $themes = ['default'=>'ÐŸÐ¾ Ð¿Ð¾Ð´Ñ€Ð°Ð·Ð±Ð¸Ñ€Ð°Ð½Ðµ','soft'=>'ÐœÐµÐºÐ° (Ð±ÐµÐ¶Ð¾Ð²Ð°)','dark'=>'Ð¢ÑŠÐ¼Ð½Ð°','ocean'=>'ÐžÐºÐµÐ°Ð½','forest'=>'Ð“Ð¾Ñ€Ð°','berry'=>'Ð“Ð¾Ñ€ÑÐºÐ¸ Ð¿Ð»Ð¾Ð´']; foreach ($themes as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($test['theme']??'default')===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ð¡Ñ‚Ð°Ñ‚ÑƒÑ</label>
                    <select name="status" class="form-select">
                        <option value="draft" <?= $test['status']==='draft'?'selected':'' ?>>Ð§ÐµÑ€Ð½Ð¾Ð²Ð°</option>
                        <option value="published" <?= $test['status']==='published'?'selected':'' ?>>ÐŸÑƒÐ±Ð»Ð¸ÐºÑƒÐ²Ð°Ð½</option>
                        <option value="archived" <?= $test['status']==='archived'?'selected':'' ?>>ÐÑ€Ñ…Ð¸Ð²Ð¸Ñ€Ð°Ð½</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ð›Ð¸Ð¼Ð¸Ñ‚ (ÑÐµÐºÑƒÐ½Ð´Ð¸)</label>
                    <input type="number" name="time_limit_sec" class="form-control" min="0" value="<?= htmlspecialchars($test['time_limit_sec']) ?>" placeholder="Ð±ÐµÐ· Ð»Ð¸Ð¼Ð¸Ñ‚" />
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_randomized" name="is_randomized" <?= ((int)$test['is_randomized']===1)?'checked':'' ?> />
                        <label class="form-check-label" for="is_randomized">D�D�D�D�S�?D�D�D�D�D� D�D� D��SD��?D_�?D,�,D�</label>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_strict_mode" name="is_strict_mode" <?= ((int)$test['is_strict_mode']===1)?'checked':'' ?> />
                        <label class="form-check-label" for="is_strict_mode">Стриктен режим (при напускане опитът се анулира)</label>
                    </div>
                </div>
            </div>

        <div id="questionsContainer"></div>

        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary" id="addQuestionBtn"><i class="bi bi-plus-lg me-1"></i>Ð”Ð¾Ð±Ð°Ð²Ð¸ Ð²ÑŠÐ¿Ñ€Ð¾Ñ</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Ð—Ð°Ð¿Ð°Ð·Ð¸ Ð¿Ñ€Ð¾Ð¼ÐµÐ½Ð¸Ñ‚Ðµ</button>
        </div>
    </form>
</main>

<template id="questionTemplate">
    <div class="card shadow-sm mb-3 q-card question-block">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex gap-2 align-items-center">
                    <select class="form-select form-select-sm qtype" style="width:auto">
                        <option value="single_choice">Ð•Ð´Ð¸Ð½ Ð²ÐµÑ€ÐµÐ½</option>
                        <option value="multiple_choice">ÐŸÐ¾Ð²ÐµÑ‡Ðµ Ð¾Ñ‚ ÐµÐ´Ð¸Ð½ Ð²ÐµÑ€ÐµÐ½</option>
                        <option value="true_false">Ð’ÑÑ€Ð½Ð¾/Ð“Ñ€ÐµÑˆÐ½Ð¾</option>
                        <option value="short_answer">ÐžÑ‚Ð²Ð¾Ñ€ÐµÐ½ Ð¾Ñ‚Ð³Ð¾Ð²Ð¾Ñ€</option>
                        <option value="numeric">Ð§Ð¸ÑÐ»ÐµÐ½ Ð¾Ñ‚Ð³Ð¾Ð²Ð¾Ñ€</option>
                    </select>
                    <input type="number" class="form-control form-control-sm points" min="0" step="0.5" value="1" style="width:120px" title="Ð¢Ð¾Ñ‡ÐºÐ¸" />
                    <select class="form-select form-select-sm difficulty" style="width:auto">
                        <option value="">Ð¢Ñ€ÑƒÐ´Ð½Ð¾ÑÑ‚</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                        <option value="5">5</option>
                    </select>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger remove-question"><i class="bi bi-trash"></i></button>
            </div>
            <div class="mb-2">
                <textarea class="form-control body" rows="2" placeholder="Ð¢ÐµÐºÑÑ‚ Ð½Ð° Ð²ÑŠÐ¿Ñ€Ð¾ÑÐ°..."></textarea>
            </div>
            <div class="mb-2">
                <label class="form-label small">ÐœÐµÐ´Ð¸Ñ (Ð¸Ð·Ð¾Ð±Ñ€Ð°Ð¶ÐµÐ½Ð¸Ðµ/PDF, Ð¿Ð¾ Ð¶ÐµÐ»Ð°Ð½Ð¸Ðµ)</label>
                <input type="file" class="form-control form-control-sm qfile" name="qfile[]" accept="image/*,application/pdf" />
                <div class="form-check mt-1">
                    <input class="form-check-input remove-media" type="checkbox" />
                    <label class="form-check-label small">ÐŸÑ€ÐµÐ¼Ð°Ñ…Ð½Ð¸ Ð½Ð°Ð»Ð¸Ñ‡Ð½Ð°Ñ‚Ð° Ð¼ÐµÐ´Ð¸Ñ</label>
                </div>
            </div>
            <div class="options-wrap"></div>
            <div class="mt-2">
                <input type="text" class="form-control form-control-sm explanation" placeholder="ÐžÐ±ÑÑÐ½ÐµÐ½Ð¸Ðµ (Ð¿Ð¾ Ð¶ÐµÐ»Ð°Ð½Ð¸Ðµ)" />
            </div>
        </div>
    </div>
</template>

<template id="optionRowTemplate">
    <div class="d-flex option-row align-items-center mb-2">
        <input type="text" class="form-control option-content" placeholder="Ð’ÑŠÐ·Ð¼Ð¾Ð¶ÐµÐ½ Ð¾Ñ‚Ð³Ð¾Ð²Ð¾Ñ€" />
        <div class="form-check ms-1 me-1">
            <input class="form-check-input option-correct" type="checkbox" />
            <label class="form-check-label">Ð²ÐµÑ€ÐµÐ½</label>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary remove-option"><i class="bi bi-x"></i></button>
    </div>
</template>

<script>
const container = document.getElementById('questionsContainer');
const qTpl = document.getElementById('questionTemplate');
const optTpl = document.getElementById('optionRowTemplate');
const addBtn = document.getElementById('addQuestionBtn');
const existing = <?= json_encode($existingQuestions, JSON_UNESCAPED_UNICODE) ?>;

function renderOptions(block, type){
  const wrap = block.querySelector('.options-wrap');
  wrap.innerHTML = '';
  if (type === 'single_choice' || type === 'multiple_choice') {
    const addOpt = document.createElement('button');
    addOpt.type = 'button';
    addOpt.className = 'btn btn-sm btn-outline-primary mb-2';
    addOpt.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Ð”Ð¾Ð±Ð°Ð²Ð¸ Ð¾Ñ‚Ð³Ð¾Ð²Ð¾Ñ€';
    addOpt.addEventListener('click', ()=>{
      const row = optTpl.content.cloneNode(true);
      row.querySelector('.remove-option').addEventListener('click', (e)=>{
        e.target.closest('.option-row').remove();
      });
      wrap.appendChild(row);
    });
    wrap.appendChild(addOpt);
  } else if (type === 'true_false') {
    wrap.innerHTML = `
      <div class="d-flex gap-2">
        <div class="form-check">
          <input class="form-check-input tf-correct" type="radio" name="tf-${Date.now()}" value="true"> <label class="form-check-label">Ð’ÑÑ€Ð½Ð¾</label>
        </div>
        <div class="form-check">
          <input class="form-check-input tf-correct" type="radio" name="tf-${Date.now()}" value="false"> <label class="form-check-label">Ð“Ñ€ÐµÑˆÐ½Ð¾</label>
        </div>
      </div>`;
  } else if (type === 'short_answer') {
    wrap.innerHTML = '<textarea class="form-control short-answers" rows="2" placeholder="Ð”Ð¾Ð¿ÑƒÑÑ‚Ð¸Ð¼Ð¸ Ð¾Ñ‚Ð³Ð¾Ð²Ð¾Ñ€Ð¸ (Ð²ÑÐµÐºÐ¸ Ð½Ð° Ð½Ð¾Ð² Ñ€ÐµÐ´ Ð¸Ð»Ð¸ Ñ€Ð°Ð·Ð´ÐµÐ»ÐµÐ½Ð¸ Ñ |)"></textarea>';
  } else if (type === 'numeric') {
    wrap.innerHTML = '<input type="number" step="any" class="form-control numeric-answer" placeholder="Ð’ÐµÑ€Ð½Ð¸ÑÑ‚ Ñ‡Ð¸ÑÐ»ÐµÐ½ Ð¾Ñ‚Ð³Ð¾Ð²Ð¾Ñ€" />';
  }
}

function addQuestion(prefill){
  const frag = qTpl.content.cloneNode(true);
  const block = frag.querySelector('.question-block');
  const select = block.querySelector('.qtype');
  const points = block.querySelector('.points');
  const diff = block.querySelector('.difficulty');
  const body = block.querySelector('.body');
  const expl = block.querySelector('.explanation');
  const removeMedia = block.querySelector('.remove-media');

  if (prefill){
    block.dataset.qid = prefill.id || '';
    select.value = prefill.qtype || 'single_choice';
    points.value = prefill.points || 1;
    if (prefill.difficulty) diff.value = prefill.difficulty;
    body.value = prefill.body || '';
    if (prefill.explanation) expl.value = prefill.explanation;
    // Show hint if media exists
    if (prefill.media_url){
      const hint = document.createElement('div');
      hint.className = 'small text-muted';
      hint.innerHTML = 'ÐÐ°Ð»Ð¸Ñ‡Ð½Ð° Ð¼ÐµÐ´Ð¸Ñ: <a href="'+prefill.media_url+'" target="_blank">Ð¿Ñ€ÐµÐ³Ð»ÐµÐ´</a>';
      block.querySelector('.q-card .card-body').insertBefore(hint, block.querySelector('.options-wrap'));
    }
  }

  renderOptions(block, select.value);

  // If prefill options
  if (prefill){
    if (select.value === 'single_choice' || select.value === 'multiple_choice') {
      const addBtn = block.querySelector('.options-wrap > .btn');
      // add each option row
      (prefill.options || []).forEach(op => {
        addBtn.click();
        const row = block.querySelector('.option-row:last-child');
        row.querySelector('.option-content').value = op.content || '';
        row.querySelector('.option-correct').checked = !!op.is_correct;
      });
    } else if (select.value === 'true_false') {
      const correct = (prefill.options || []).find(op => op.is_correct);
      if (correct){
        const val = correct.content === 'Ð’ÑÑ€Ð½Ð¾' ? 'true' : 'false';
        const radio = block.querySelector(`.tf-correct[value="${val}"]`);
        if (radio) radio.checked = true;
      }
    } else if (select.value === 'short_answer') {
      block.querySelector('.short-answers').value = prefill.short_answers || '';
    } else if (select.value === 'numeric') {
      block.querySelector('.numeric-answer').value = prefill.numeric_answer || '';
    }
  }

  select.addEventListener('change', ()=> renderOptions(block, select.value));
  block.querySelector('.remove-question').addEventListener('click', ()=> block.remove());
  container.appendChild(frag);
}

document.getElementById('testForm').addEventListener('submit', function(e){
  const qBlocks = container.querySelectorAll('.question-block');
  if (!qBlocks.length){
    alert('Ð”Ð¾Ð±Ð°Ð²ÐµÑ‚Ðµ Ð¿Ð¾Ð½Ðµ ÐµÐ´Ð¸Ð½ Ð²ÑŠÐ¿Ñ€Ð¾Ñ.');
    e.preventDefault();
    return;
  }
  let i = 0;
  qBlocks.forEach(block => {
    const qtype = block.querySelector('.qtype').value;
    const points = block.querySelector('.points').value;
    const difficulty = block.querySelector('.difficulty').value;
    const body = block.querySelector('.body').value;
    const explanation = block.querySelector('.explanation').value;
    const qid = block.dataset.qid || '';

    const addHidden = (name, value) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = `questions[${i}][${name}]`;
      input.value = value;
      e.target.appendChild(input);
    };

    if (qid) addHidden('id', qid);
    addHidden('qtype', qtype);
    addHidden('points', points);
    if (difficulty) addHidden('difficulty', difficulty);
    addHidden('body', body);
    if (explanation) addHidden('explanation', explanation);

    if (qtype === 'single_choice' || qtype === 'multiple_choice') {
      const rows = block.querySelectorAll('.option-row');
      let j = 0;
      rows.forEach(row => {
        const content = row.querySelector('.option-content').value;
        const correct = row.querySelector('.option-correct').checked ? '1' : '';
        if (!content) return;
        const c = document.createElement('input'); c.type='hidden'; c.name=`questions[${i}][options][${j}][content]`; c.value=content; e.target.appendChild(c);
        const k = document.createElement('input'); k.type='hidden'; k.name=`questions[${i}][options][${j}][is_correct]`; k.value=correct; e.target.appendChild(k);
        j++;
      });
    } else if (qtype === 'true_false') {
      const selected = block.querySelector('.tf-correct:checked');
      if (selected){
        const val = selected.value === 'true' ? 'Ð’ÑÑ€Ð½Ð¾' : 'Ð“Ñ€ÐµÑˆÐ½Ð¾';
        const c = document.createElement('input'); c.type='hidden'; c.name=`questions[${i}][options][0][content]`; c.value='Ð’ÑÑ€Ð½Ð¾'; e.target.appendChild(c);
        const k = document.createElement('input'); k.type='hidden'; k.name=`questions[${i}][options][0][is_correct]`; k.value=(val==='Ð’ÑÑ€Ð½Ð¾')?'1':''; e.target.appendChild(k);
        const c2 = document.createElement('input'); c2.type='hidden'; c2.name=`questions[${i}][options][1][content]`; c2.value='Ð“Ñ€ÐµÑˆÐ½Ð¾'; e.target.appendChild(c2);
        const k2 = document.createElement('input'); k2.type='hidden'; k2.name=`questions[${i}][options][1][is_correct]`; k2.value=(val==='Ð“Ñ€ÐµÑˆÐ½Ð¾')?'1':''; e.target.appendChild(k2);
      }
    } else if (qtype === 'short_answer') {
      const text = block.querySelector('.short-answers').value;
      if (text) addHidden('short_answers', text);
    } else if (qtype === 'numeric') {
      const num = block.querySelector('.numeric-answer').value;
      if (num) addHidden('numeric_answer', num);
    }

    i++;
  });
});

// Render existing
existing.forEach(q => addQuestion(q));

// Add new
document.getElementById('addQuestionBtn').addEventListener('click', ()=> addQuestion());
</script>

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
</footer>
</body>
</html>
    const rm = block.querySelector('.remove-media');
    if (rm && rm.checked) addHidden('remove_media','1');
