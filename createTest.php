<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'teacher') {
    header('Location: login.php');
    exit;
}
$user = $_SESSION['user'];
$pdo = db();
ensure_test_theme_and_q_media($pdo);

/* ---------------- Helpers ---------------- */
function norm_visibility(?string $v): string
{
    $v = strtolower(trim((string) $v));
    return in_array($v, ['private', 'shared'], true) ? $v : 'private';
}
function to_int($v, $min = null, $max = null)
{
    $x = (int) $v;
    if ($min !== null && $x < $min)
        $x = $min;
    if ($max !== null && $x > $max)
        $x = $max;
    return $x;
}
function map_ui_to_qtype(string $ui): string
{
    // DB enum: single_choice | multiple_choice | true_false | short_answer | numeric
    return $ui === 'multiple' ? 'multiple_choice' : 'single_choice';
}
function map_qtype_to_ui(?string $qt): string
{
    return $qt === 'multiple_choice' ? 'multiple' : 'single';
}

/* ---------------- Page state ---------------- */
$errors = [];
$saved = false;
$test_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $test_id > 0;
$test = null;
$questions = [];

if ($editing) {
    // Лоуд на тест само ако е собственик
    $stmt = $pdo->prepare('SELECT * FROM tests WHERE id = :id AND owner_teacher_id = :tid');
    $stmt->execute([':id' => $test_id, ':tid' => $user['id']]);
    $test = $stmt->fetch();
    if (!$test) {
        header('Location: createTest.php');
        exit;
    }
    // Лоуд на въпросите
    $q = $pdo->prepare('
        SELECT qb.id AS question_id, qb.body AS q_body, qb.qtype AS qtype,
               tq.points, tq.order_index
        FROM test_questions tq
        JOIN question_bank qb ON qb.id = tq.question_id
        WHERE tq.test_id = :tid
        ORDER BY tq.order_index, qb.id
    ');
    $q->execute([':tid' => $test_id]);
    $rows = $q->fetchAll();

    $ansStmt = $pdo->prepare('SELECT id, content, is_correct FROM answers WHERE question_id = :qid ORDER BY COALESCE(order_index,9999), id');
    foreach ($rows as $r) {
        $ansStmt->execute([':qid' => $r['question_id']]);
        $answers = $ansStmt->fetchAll();
        $questions[] = [
            'question_id' => (int) $r['question_id'],
            'content' => $r['q_body'],
            'type' => map_qtype_to_ui($r['qtype']),
            'points' => (float) $r['points'],
            'answers' => array_map(function ($a) {
                return ['content' => $a['content'], 'is_correct' => (int) $a['is_correct']];
            }, $answers),
        ];
    }
}

$subject_id = $editing && isset($test['subject_id']) ? (int) $test['subject_id'] : null;
$subjectChoices = [];
try {
    $stmt = $pdo->prepare('SELECT id, name FROM subjects WHERE owner_teacher_id IS NULL OR owner_teacher_id = 0 OR owner_teacher_id = :tid ORDER BY name');
    $stmt->execute([':tid' => (int) $user['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $subjectChoices[(int) $row['id']] = $row['name'];
    }
} catch (Throwable $e) {
    $subjectChoices = [];
}
if ($subject_id !== null && !isset($subjectChoices[$subject_id])) {
    try {
        $stmt = $pdo->prepare('SELECT id, name FROM subjects WHERE id = :id');
        $stmt->execute([':id' => $subject_id]);
        if ($row = $stmt->fetch()) {
            $subjectChoices[(int) $row['id']] = $row['name'];
        }
    } catch (Throwable $e) {
        // ignore
    }
}
if ($subjectChoices) {
    asort($subjectChoices, SORT_STRING | SORT_FLAG_CASE);
}

/* ---------------- Handle POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Основни полета
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $visibility = norm_visibility($_POST['visibility'] ?? 'private');
    $status = in_array(($_POST['status'] ?? 'draft'), ['draft', 'published'], true) ? $_POST['status'] : 'draft';
    $time_limit = isset($_POST['time_limit_sec']) ? to_int($_POST['time_limit_sec'], 0, 86400) : null;
    $max_attempts = to_int($_POST['max_attempts'] ?? 0, 0, 100);
    $is_randomized = !empty($_POST['is_randomized']) ? 1 : 0;
    $is_strict_mode = !empty($_POST['is_strict_mode']) ? 1 : 0;
    $theme = trim((string) ($_POST['theme'] ?? 'default'));
    $subject_id = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? (int) $_POST['subject_id'] : null;
    if ($subject_id !== null && !isset($subjectChoices[$subject_id])) {
        $errors[] = 'Избраният предмет не е валиден.';
        $subject_id = null;
    }

    // Въпроси (масив)
    $questions = $_POST['questions'] ?? [];

    if ($title === '')
        $errors[] = 'Моля, въведете заглавие на теста.';
    if (!is_array($questions) || count($questions) === 0)
        $errors[] = 'Добавете поне един въпрос.';

    // Валидация на всеки въпрос/отговори
    foreach ($questions as $idx => $q) {
        $q_content = trim((string) ($q['content'] ?? ''));
        $q_type_ui = in_array(($q['type'] ?? 'single'), ['single', 'multiple'], true) ? $q['type'] : 'single';
        $q_points = (float) ($q['points'] ?? 1);
        $ans = $q['answers'] ?? [];

        if ($q_content === '')
            $errors[] = "Въпрос #" . ($idx + 1) . ": липсва съдържание.";
        if (!is_array($ans) || count($ans) < 2)
            $errors[] = "Въпрос #" . ($idx + 1) . ": въведете поне 2 отговора.";

        $correctCount = 0;
        foreach ($ans as $aIdx => $a) {
            $a_content = trim((string) ($a['content'] ?? ''));
            $a_correct = !empty($a['is_correct']) ? 1 : 0;
            if ($a_content === '')
                $errors[] = "Въпрос #" . ($idx + 1) . ", Отговор #" . ($aIdx + 1) . ": съдържанието е празно.";
            if ($a_correct)
                $correctCount++;
        }
        if ($q_type_ui === 'single' && $correctCount !== 1) {
            $errors[] = "Въпрос #" . ($idx + 1) . ": за 'единичен избор' трябва да има точно 1 верен отговор.";
        } elseif ($q_type_ui === 'multiple' && $correctCount === 0) {
            $errors[] = "Въпрос #" . ($idx + 1) . ": за 'множествен избор' трябва да има поне 1 верен отговор.";
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($editing) {
                // Обнови теста
                $stmt = $pdo->prepare('
                    UPDATE tests
                    SET title=:title, description=:descr, visibility=:vis, status=:st, subject_id=:sub,
                        time_limit_sec=:tls, max_attempts=:maxa, is_randomized=:rand, is_strict_mode=:strict, theme=:theme
                    WHERE id=:id AND owner_teacher_id=:tid
                ');
                $stmt->execute([
                    ':title' => $title,
                    ':descr' => $description !== '' ? $description : null,
                    ':vis' => $visibility,
                    ':st' => $status,
                    ':sub' => $subject_id !== null ? $subject_id : null,
                    ':tls' => ($time_limit !== null ? $time_limit : null),
                    ':maxa' => $max_attempts,
                    ':rand' => $is_randomized,
                    ':strict' => $is_strict_mode,
                    ':theme' => $theme !== '' ? $theme : 'default',
                    ':id' => $test_id,
                    ':tid' => $user['id'],
                ]);

                // Изчисти старите връзки тест-въпрос
                $pdo->prepare('DELETE FROM test_questions WHERE test_id = :tid')->execute([':tid' => $test_id]);
            } else {
                // Създай тест (AUTO_INCREMENT)
                $stmt = $pdo->prepare('
                    INSERT INTO tests (owner_teacher_id, subject_id, title, description, visibility, status, time_limit_sec, max_attempts, is_randomized, is_strict_mode, theme)
                    VALUES (:tid, :sub, :title, :descr, :vis, :st, :tls, :maxa, :rand, :strict, :theme)
                ');
                $stmt->execute([
                    ':tid' => $user['id'],
                    ':sub' => $subject_id !== null ? $subject_id : null,
                    ':title' => $title,
                    ':descr' => $description !== '' ? $description : null,
                    ':vis' => $visibility,
                    ':st' => $status,
                    ':tls' => ($time_limit !== null ? $time_limit : null),
                    ':maxa' => $max_attempts,
                    ':rand' => $is_randomized,
                    ':strict' => $is_strict_mode,
                    ':theme' => $theme !== '' ? $theme : 'default',
                ]);

                $test_id = (int) $pdo->lastInsertId();
                if ($test_id <= 0) {
                    throw new RuntimeException('Таблица tests вероятно няма AUTO_INCREMENT за id.');
                }
                $editing = true;
            }

            // Вкарай въпросите/отговорите
            $order = 1;
            $insQ = $pdo->prepare('INSERT INTO question_bank (owner_teacher_id, visibility, qtype, body) VALUES (:tid, :vis, :qtype, :body)');
            $insA = $pdo->prepare('INSERT INTO answers (question_id, content, is_correct, order_index) VALUES (:qid, :content, :is_correct, :ord)');
            $insLink = $pdo->prepare('INSERT INTO test_questions (test_id, question_id, points, order_index) VALUES (:tid, :qid, :points, :ord)');

            foreach ($questions as $q) {
                $q_content = trim((string) $q['content']);
                $q_type_ui = in_array(($q['type'] ?? 'single'), ['single', 'multiple'], true) ? $q['type'] : 'single';
                $q_points = (float) ($q['points'] ?? 1);
                $answers = $q['answers'];

                // нов въпрос (съобразено със schema: qtype, body)
                $insQ->execute([
                    ':tid' => $user['id'],
                    ':vis' => $visibility,                         // ползваме видимостта на теста
                    ':qtype' => map_ui_to_qtype($q_type_ui),         // single_choice / multiple_choice
                    ':body' => $q_content,                          // текст на въпроса
                ]);
                $qid = (int) $pdo->lastInsertId();
                if ($qid <= 0) {
                    throw new RuntimeException('question_bank няма AUTO_INCREMENT за id.');
                }

                // отговори
                $aOrder = 1;
                foreach ($answers as $a) {
                    $insA->execute([
                        ':qid' => $qid,
                        ':content' => trim((string) $a['content']),
                        ':is_correct' => !empty($a['is_correct']) ? 1 : 0,
                        ':ord' => $aOrder++,
                    ]);
                }

                // връзка тест-въпрос
                $insLink->execute([
                    ':tid' => $test_id,
                    ':qid' => $qid,
                    ':points' => $q_points,
                    ':ord' => $order++,
                ]);
            }

            $pdo->commit();
            $saved = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $errors[] = 'Грешка при запис: ' . $e->getMessage();
        }
    }
}

/* ---------------- View state ---------------- */
$view = [
    'title' => $test['title'] ?? '',
    'description' => $test['description'] ?? '',
    'visibility' => $test['visibility'] ?? 'private',
    'status' => $test['status'] ?? 'draft',
    'subject_id' => $subject_id ?? '',
    'time_limit' => isset($test['time_limit_sec']) ? (int) $test['time_limit_sec'] : '',
    'max_attempts' => isset($test['max_attempts']) ? (int) $test['max_attempts'] : 0,
    'is_randomized' => !empty($test['is_randomized']) ? 1 : 0,
    'is_strict_mode' => !empty($test['is_strict_mode']) ? 1 : 0,
    'theme' => $test['theme'] ?? 'default',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // sticky
    $view['title'] = htmlspecialchars($title ?? $view['title']);
    $view['description'] = htmlspecialchars($description ?? $view['description']);
    $view['visibility'] = htmlspecialchars($visibility ?? $view['visibility']);
    $view['status'] = htmlspecialchars($status ?? $view['status']);
    $view['subject_id'] = $subject_id !== null ? (int) $subject_id : '';
    $view['time_limit'] = htmlspecialchars((string) ($time_limit ?? $view['time_limit']));
    $view['max_attempts'] = htmlspecialchars((string) ($max_attempts ?? $view['max_attempts']));
    $view['is_randomized'] = !empty($is_randomized) ? 1 : 0;
    $view['is_strict_mode'] = !empty($is_strict_mode) ? 1 : 0;
    $view['theme'] = htmlspecialchars($theme ?? $view['theme']);
}
?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= $editing ? 'Редакция на тест' : 'Създаване на тест' ?> – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .answer-row {
            display: flex;
            gap: .5rem;
            margin-bottom: .5rem;
        }

        .answer-row input[type="text"] {
            flex: 1;
        }

        .q-card {
            border: 1px solid #e9ecef;
            border-radius: .5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-4 my-md-5">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h1 class="h4 m-0"><?= $editing ? 'Редакция на тест' : 'Създаване на тест' ?></h1>
            <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
        </div>

        <?php if ($saved): ?>
            <div class="alert alert-success">Тестът е запазен. ID: <strong><?= (int) $test_id ?></strong></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="m-0 ps-3"><?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="card shadow-sm mb-4" id="testForm">
            <div class="card-header bg-white"><strong>Основни данни</strong></div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Заглавие</label>
                    <input type="text" name="title" class="form-control" value="<?= $view['title'] ?>" required />
                </div>
                <div class="col-md-6">
                    <label class="form-label">Видимост</label>
                    <select name="visibility" class="form-select">
                        <option value="private" <?= $view['visibility'] === 'private' ? 'selected' : '' ?>>Само аз</option>
                        <option value="shared" <?= $view['visibility'] === 'shared' ? 'selected' : '' ?>>Споделен</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Описание</label>
                    <textarea name="description" rows="2" class="form-control"><?= $view['description'] ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Предмет</label>
                    <select name="subject_id" class="form-select">
                        <option value="">Без предмет</option>
                        <?php foreach ($subjectChoices as $sid => $sname): ?>
                            <option value="<?= (int) $sid ?>" <?= ((string) $view['subject_id'] === (string) $sid) ? 'selected' : '' ?>><?= htmlspecialchars($sname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Статус</label>
                    <select name="status" class="form-select">
                        <option value="draft" <?= $view['status'] === 'draft' ? 'selected' : '' ?>>Чернова</option>
                        <option value="published" <?= $view['status'] === 'published' ? 'selected' : '' ?>>Публикуван
                        </option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Лимит време (сек)</label>
                    <input type="number" name="time_limit_sec" class="form-control" min="0"
                        value="<?= $view['time_limit'] ?>" />
                </div>
                <div class="col-md-3">
                    <label class="form-label">Макс. опити</label>
                    <input type="number" name="max_attempts" class="form-control" min="0"
                        value="<?= $view['max_attempts'] ?>" />
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_randomized" name="is_randomized"
                            <?= $view['is_randomized'] ? 'checked' : '' ?> />
                        <label class="form-check-label" for="is_randomized">Разбъркване</label>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_strict_mode" name="is_strict_mode"
                            <?= !empty($view['is_strict_mode']) ? 'checked' : '' ?> />
                        <label class="form-check-label" for="is_strict_mode">Стриктен режим (при напускане опитът се анулира)</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Тема</label>
                    <input type="text" name="theme" class="form-control" value="<?= $view['theme'] ?>" />
                </div>
            </div>

            <div class="card-header bg-white border-top"><strong>Въпроси</strong></div>
            <div class="card-body">
                <div id="questions">
                    <?php if ($questions): ?>
                        <?php foreach ($questions as $qi => $q): ?>
                            <div class="q-card" data-q>
                                <div class="row g-2">
                                    <div class="col-md-8">
                                        <label class="form-label">Текст на въпроса</label>
                                        <input type="text" name="questions[<?= $qi ?>][content]" class="form-control"
                                            value="<?= htmlspecialchars($q['content']) ?>" required />
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Тип</label>
                                        <select name="questions[<?= $qi ?>][type]" class="form-select">
                                            <option value="single" <?= ($q['type'] === 'single') ? 'selected' : '' ?>>Единичен
                                                избор</option>
                                            <option value="multiple" <?= ($q['type'] === 'multiple') ? 'selected' : '' ?>>
                                                Множествен избор</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Точки</label>
                                        <input type="number" step="0.01" name="questions[<?= $qi ?>][points]"
                                            class="form-control" min="0" value="<?= (float) $q['points'] ?>" />
                                    </div>
                                </div>

                                <div class="mt-2" data-answers>
                                    <?php foreach ($q['answers'] as $ai => $a): ?>
                                        <div class="answer-row" data-answer>
                                            <input type="text" name="questions[<?= $qi ?>][answers][<?= $ai ?>][content]"
                                                value="<?= htmlspecialchars($a['content']) ?>" class="form-control"
                                                placeholder="Отговор..." required />
                                            <div class="form-check d-flex align-items-center">
                                                <input class="form-check-input" type="checkbox"
                                                    name="questions[<?= $qi ?>][answers][<?= $ai ?>][is_correct]"
                                                    <?= !empty($a['is_correct']) ? 'checked' : '' ?> />
                                                <label class="form-check-label ms-1">Верен</label>
                                            </div>
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="rmAnswer(this)"><i
                                                    class="bi bi-x"></i></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm"
                                        onclick="addAnswer(this)">Добави отговор</button>
                                    <button type="button" class="btn btn-outline-danger btn-sm float-end"
                                        onclick="rmQuestion(this)">Премахни въпроса</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Празен стартов блок -->
                        <div class="q-card" data-q>
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <label class="form-label">Текст на въпроса</label>
                                    <input type="text" name="questions[0][content]" class="form-control" required />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Тип</label>
                                    <select name="questions[0][type]" class="form-select">
                                        <option value="single">Единичен избор</option>
                                        <option value="multiple">Множествен избор</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Точки</label>
                                    <input type="number" step="0.01" name="questions[0][points]" class="form-control"
                                        min="0" value="1" />
                                </div>
                            </div>
                            <div class="mt-2" data-answers>
                                <div class="answer-row" data-answer>
                                    <input type="text" name="questions[0][answers][0][content]" class="form-control"
                                        placeholder="Отговор..." required />
                                    <div class="form-check d-flex align-items-center">
                                        <input class="form-check-input" type="checkbox"
                                            name="questions[0][answers][0][is_correct]" />
                                        <label class="form-check-label ms-1">Верен</label>
                                    </div>
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="rmAnswer(this)"><i
                                            class="bi bi-x"></i></button>
                                </div>
                                <div class="answer-row" data-answer>
                                    <input type="text" name="questions[0][answers][1][content]" class="form-control"
                                        placeholder="Отговор..." required />
                                    <div class="form-check d-flex align-items-center">
                                        <input class="form-check-input" type="checkbox"
                                            name="questions[0][answers][1][is_correct]" />
                                        <label class="form-check-label ms-1">Верен</label>
                                    </div>
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="rmAnswer(this)"><i
                                            class="bi bi-x"></i></button>
                                </div>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="addAnswer(this)">Добави отговор</button>
                                <button type="button" class="btn btn-outline-danger btn-sm float-end"
                                    onclick="rmQuestion(this)">Премахни въпроса</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="button" class="btn btn-outline-primary" onclick="addQuestion()">Добави въпрос</button>
            </div>

            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Запази
                    теста</button>
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
        <script>
            // динамика за въпроси/отговори
            function renumber() {
                const qs = document.querySelectorAll('[data-q]');
                qs.forEach((qEl, qi) => {
                    qEl.querySelectorAll('input, select, textarea').forEach(inp => {
                        inp.name = inp.name.replace(/questions\[\d+\]/, 'questions[' + qi + ']');
                    });
                    const as = qEl.querySelectorAll('[data-answer]');
                    as.forEach((aEl, ai) => {
                        aEl.querySelectorAll('input').forEach(inp => {
                            inp.name = inp.name.replace(/questions\[\d+\]\[answers\]\[\d+\]/, 'questions[' + qi + '][answers][' + ai + ']');
                        });
                    });
                });
            }
            function addQuestion() {
                const wrap = document.getElementById('questions');
                const tmpl = wrap.querySelector('[data-q]').cloneNode(true);
                tmpl.querySelectorAll('input[type="text"]').forEach(i => i.value = '');
                tmpl.querySelectorAll('textarea').forEach(i => i.value = '');
                tmpl.querySelectorAll('input[type="number"]').forEach(i => i.value = '1');
                tmpl.querySelectorAll('input[type="checkbox"]').forEach(i => i.checked = false);
                const answersWrap = tmpl.querySelector('[data-answers]');
                answersWrap.innerHTML = '';
                for (let k = 0; k < 2; k++) {
                    const row = document.createElement('div');
                    row.className = 'answer-row';
                    row.setAttribute('data-answer', '');
                    row.innerHTML = `
                    <input type="text" class="form-control" name="questions[0][answers][${k}][content]" placeholder="Отговор..." required />
                    <div class="form-check d-flex align-items-center">
                        <input class="form-check-input" type="checkbox" name="questions[0][answers][${k}][is_correct]" />
                        <label class="form-check-label ms-1">Верен</label>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="rmAnswer(this)"><i class="bi bi-x"></i></button>
                `;
                    answersWrap.appendChild(row);
                }
                wrap.appendChild(tmpl);
                renumber();
            }
            function rmQuestion(btn) {
                const card = btn.closest('[data-q]');
                const wrap = document.getElementById('questions');
                if (wrap.querySelectorAll('[data-q]').length <= 1) { alert('Трябва да има поне един въпрос.'); return; }
                card.remove(); renumber();
            }
            function addAnswer(btn) {
                const qEl = btn.closest('[data-q]');
                const answersWrap = qEl.querySelector('[data-answers]');
                const qi = Array.from(document.querySelectorAll('[data-q]')).indexOf(qEl);
                const ai = answersWrap.querySelectorAll('[data-answer]').length;
                const row = document.createElement('div');
                row.className = 'answer-row'; row.setAttribute('data-answer', '');
                row.innerHTML = `
                <input type="text" class="form-control" name="questions[${qi}][answers][${ai}][content]" placeholder="Отговор..." required />
                <div class="form-check d-flex align-items-center">
                    <input class="form-check-input" type="checkbox" name="questions[${qi}][answers][${ai}][is_correct]" />
                    <label class="form-check-label ms-1">Верен</label>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="rmAnswer(this)"><i class="bi bi-x"></i></button>
            `;
                answersWrap.appendChild(row);
            }
            function rmAnswer(btn) {
                const answersWrap = btn.closest('[data-answers]');
                if (answersWrap.querySelectorAll('[data-answer]').length <= 2) { alert('Поне 2 отговора са задължителни.'); return; }
                btn.closest('[data-answer]').remove(); renumber();
            }
        </script>
    </footer>
</body>

</html>
