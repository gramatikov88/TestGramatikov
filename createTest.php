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
    if ($ui === 'multiple')
        return 'multiple_choice';
    if ($ui === 'true_false')
        return 'true_false';
    if ($ui === 'fill')
        return 'short_answer';
    return 'single_choice';
}
function map_qtype_to_ui(?string $qt): string
{
    if ($qt === 'multiple_choice')
        return 'multiple';
    if ($qt === 'true_false')
        return 'true_false';
    if ($qt === 'short_answer')
        return 'fill';
    return 'single';
}

function extract_question_media(array $files, int $index): ?array
{
    if (empty($files) || !isset($files['error'][$index]) || $files['error'][$index] !== UPLOAD_ERR_OK) {
        return null;
    }
    return [
        'tmp_name' => $files['tmp_name'][$index],
        'name' => $files['name'][$index],
        'type' => $files['type'][$index] ?? '',
        'size' => $files['size'][$index] ?? 0,
    ];
}

function detect_upload_mime(string $tmp): ?string
{
    $mime = @mime_content_type($tmp);
    if (!$mime) {
        return null;
    }
    return $mime;
}

/** Нормализация на стойности от колоната "Type" в Excel. */
function normalize_ui_type(string $raw): string
{
    $t = strtolower(trim($raw));
    $t = str_replace(['-', ' '], '_', $t);

    if (in_array($t, ['single', 'single_choice'], true))
        return 'single';
    if (in_array($t, ['multiple', 'multiple_choice'], true))
        return 'multiple';

    if (in_array($t, ['singlechoice'], true))
        return 'single';
    if (in_array($t, ['multiplechoice'], true))
        return 'multiple';

    if (in_array($t, ['fill', 'fill_blank', 'fill_blanks', 'fill_in_blank', 'fill_in_blanks', 'fill_in_the_blank', 'short_answer'], true))
        return 'fill';

    if (in_array($t, ['попълни', 'попълни_липсващите_думи', 'попълни_думите'], true))
        return 'fill';

    if (in_array($t, ['true_false', 'truefalse', 'tf', 'boolean', 'bool', 'yes_no', 'yesno'], true))
        return 'true_false';

    if (in_array($t, ['вярно_грешно', 'вярногрешно', 'да_не', 'дане'], true))
        return 'true_false';

    if (in_array($t, ['единичен', 'единствен', 'единичен_избор'], true))
        return 'single';
    if (in_array($t, ['множествен', 'множествен_избор', 'множество'], true))
        return 'multiple';

    $t2 = preg_replace('/[^a-zа-я0-9_]+/u', '', $t);
    if (in_array($t2, ['single', 'singlechoice'], true))
        return 'single';
    if (in_array($t2, ['multiple', 'multiplechoice'], true))
        return 'multiple';

    if (in_array($t2, ['fill', 'fillblank', 'fillblanks', 'fillinblank', 'fillintheblank', 'shortanswer'], true))
        return 'fill';

    if (in_array($t2, ['попълнилипсващитедуми', 'попълнидумите'], true))
        return 'fill';

    if (in_array($t2, ['true_false', 'truefalse', 'tf', 'boolean', 'bool', 'yesno', 'yes_no'], true))
        return 'true_false';

    return 'single';
}

function import_questions_from_excel(string $filePath, array &$errors): array
{
    // Зареждаме библиотеката, но класът може да е или \Shuchkin\SimpleXLSX, или глобален SimpleXLSX
    if (!class_exists('\Shuchkin\SimpleXLSX') && !class_exists('SimpleXLSX')) {
        $libPath = __DIR__ . '/lib/SimpleXLSX.php';
        if (is_file($libPath)) {
            require_once $libPath;
        }
    }
    // Избор на правилния клас
    $cls = null;
    if (class_exists('\Shuchkin\SimpleXLSX')) {
        $cls = '\Shuchkin\SimpleXLSX';
    } elseif (class_exists('SimpleXLSX')) {
        $cls = 'SimpleXLSX';
    } else {
        $errors[] = 'SimpleXLSX library not found. Put lib/SimpleXLSX.php (vendor version with namespace Shuchkin).';
        return [];
    }

    $xlsx = $cls::parse($filePath);
    if (!$xlsx) {
        // извикваме статичната грешка, ако я има
        $err = method_exists($cls, 'parseError') ? $cls::parseError() : 'Неуспешно четене на Excel файла.';
        $errors[] = 'Неуспешно четене на Excel файла: ' . $err;
        return [];
    }

    // rows() API е съвместим и при двете версии
    $rows = $xlsx->rows();
    if (!$rows || count($rows) < 2) {
        $errors[] = 'Excel файлът не съдържа данни за импортиране.';
        return [];
    }

    $header = array_map(function ($cell) {
        return strtolower(trim((string) $cell));
    }, $rows[0]);

    $questionCol = $typeCol = $pointsCol = $correctCol = null;
    $answerColumns = [];

    foreach ($header as $idx => $label) {
        if ($label === 'question') {
            $questionCol = $idx;
        } elseif ($label === 'type') {
            $typeCol = $idx;
        } elseif ($label === 'points') {
            $pointsCol = $idx;
        } elseif ($label === 'correct' || $label === 'correct answers') {
            $correctCol = $idx;
        } elseif (preg_match('/^answer\s*(\d+)$/', $label, $m)) {
            $answerColumns[] = ['col' => $idx, 'slot' => (int) $m[1]];
        }
    }

    if ($questionCol === null || $correctCol === null || count($answerColumns) < 2) {
        $errors[] = 'Expected header columns: Question, Type, Points, Answer 1..N, and Correct.';
        return [];
    }

    usort($answerColumns, function (array $a, array $b) {
        return $a['slot'] <=> $b['slot'];
    });

    $questions = [];
    $rowCount = count($rows);
    $supportedTypes = ['single', 'multiple', 'true_false', 'fill'];
    for ($i = 1; $i < $rowCount; $i++) {
        $row = $rows[$i];
        $rowNumber = $i + 1;
        $questionText = trim((string) ($row[$questionCol] ?? ''));

        $answersRaw = [];
        foreach ($answerColumns as $info) {
            $answerText = trim((string) ($row[$info['col']] ?? ''));
            if ($answerText !== '') {
                $answersRaw[] = ['content' => $answerText, 'slot' => $info['slot']];
            }
        }

        if ($questionText === '') {
            $hasAnswerData = false;
            foreach ($answersRaw as $candidate) {
                if ($candidate['content'] !== '') {
                    $hasAnswerData = true;
                    break;
                }
            }
            if (!$hasAnswerData) {
                continue;
            }
            $errors[] = 'Row ' . $rowNumber . ': missing question text.';
            continue;
        }

        $type = 'single';
        if ($typeCol !== null) {
            $rawType = trim((string) ($row[$typeCol] ?? 'single'));
            $norm = normalize_ui_type($rawType);
            if (in_array($norm, $supportedTypes, true)) {
                $type = $norm;
            } elseif ($rawType !== '') {
                $errors[] = 'Row ' . $rowNumber . ': unsupported type "' . $rawType . '". Using "single" (allowed: single, multiple, true_false, fill).';
            }
        }

        $minAnswers = $type === 'fill' ? 1 : 2;
        if (count($answersRaw) < $minAnswers) {
            $errors[] = 'Row ' . $rowNumber . ': provide at least ' . $minAnswers . ' answer(s).';
            continue;
        }

        $points = 1.0;
        if ($pointsCol !== null) {
            $rawPoints = trim((string) ($row[$pointsCol] ?? ''));
            if ($rawPoints !== '') {
                $normalizedPoints = str_replace(',', '.', $rawPoints);
                if (is_numeric($normalizedPoints)) {
                    $points = (float) $normalizedPoints;
                } else {
                    $errors[] = 'Row ' . $rowNumber . ': points must be numeric.';
                }
            }
            if ($points < 0) {
                $errors[] = 'Row ' . $rowNumber . ': points cannot be negative.';
                $points = 0;
            }
        }

        $correctRaw = trim((string) ($row[$correctCol] ?? ''));
        $correctSlots = [];
        if ($correctRaw !== '') {
            $tokens = preg_split('/[;,\s]+/', $correctRaw);
            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token === '')
                    continue;
                if (ctype_digit($token)) {
                    $correctSlots[] = (int) $token;
                } elseif (preg_match('/^[A-Za-z]$/', $token)) {
                    $correctSlots[] = ord(strtoupper($token)) - 64;
                }
            }
        }
        $correctSlots = array_unique($correctSlots);

        $answerList = [];
        $correctCount = 0;
        if ($type === 'fill') {
            foreach ($answersRaw as $candidate) {
                $answerList[] = [
                    'content' => $candidate['content'],
                    'is_correct' => 1,
                ];
            }
            $correctCount = count($answerList);
        } else {
            foreach ($answersRaw as $candidate) {
                $isCorrect = in_array($candidate['slot'], $correctSlots, true) ? 1 : 0;
                if ($isCorrect)
                    $correctCount++;
                $answerList[] = [
                    'content' => $candidate['content'],
                    'is_correct' => $isCorrect,
                ];
            }
        }

        if ($type === 'true_false' && count($answerList) !== 2) {
            $errors[] = 'Row ' . $rowNumber . ': true/false questions must have exactly two answers.';
            continue;
        }

        if (in_array($type, ['single', 'true_false'], true) && $correctCount !== 1) {
            $errors[] = 'Row ' . $rowNumber . ': Въпроси с единичен избор трябва да имат точно един верен отговор.';
            continue;
        }
        if ($type === 'multiple' && $correctCount === 0) {
            $errors[] = 'Row ' . $rowNumber . ': Въпроси с множествен избор трябва да имат поне един верен отговор.';
            continue;
        }

        $questions[] = [
            'content' => $questionText,
            'type' => $type,
            'points' => $points,
            'answers' => $answerList,
            'media_url' => '',
            'media_mime' => '',
            'existing_media_url' => '',
            'existing_media_mime' => '',
            'remove_media' => 0,
            'media_upload' => null,
            'media_upload_mime' => null,
        ];
    }

    return $questions;
}


/* ---------------- Page state ---------------- */
$errors = [];
$saved = false;
$test_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $test_id > 0;
$test = null;
$questions = [];
$importNotice = null;

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
               qb.media_url, qb.media_mime,
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
            'media_url' => $r['media_url'],
            'media_mime' => $r['media_mime'],
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
    $isImport = isset($_POST['import_excel']);
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
    $questionMediaFiles = $_FILES['question_media'] ?? null;
    $processedQuestions = [];

    if ($isImport) {
        $existingQuestions = $_POST['questions'] ?? [];
        if (!is_array($existingQuestions)) {
            $existingQuestions = [];
        }
        $questions = $existingQuestions;

        $excelFile = $_FILES['excel_file'] ?? null;
        if (!$excelFile || ($excelFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please choose an Excel (.xlsx) file to import.';
        } elseif (($excelFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'The Excel file could not be uploaded.';
        } else {
            $extension = strtolower(pathinfo($excelFile['name'] ?? '', PATHINFO_EXTENSION));
            if ($extension !== 'xlsx') {
                $errors[] = 'Only .xlsx files are supported for import.';
            } else {
                try {
                    $importedQuestions = import_questions_from_excel($excelFile['tmp_name'], $errors);
                    if ($importedQuestions) {
                        $questions = array_values($importedQuestions);
                        $importNotice = count($importedQuestions) . ' въпросите са заредени от Excel файла. Прегледайте ги, направете корекции ако е необходимо и натиснете „Запази“, за да запазите теста.';
                    } elseif (!$errors) {
                        $errors[] = 'Не бяха открити въпроси в Excel файла.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Неуспешен импорт на Excel файла: ' . $e->getMessage();
                }
            }
        }
    } else {

        if ($title === '')
            $errors[] = 'Моля, въведете заглавие на теста.';
        if (!is_array($questions) || count($questions) === 0)
            $errors[] = 'Добавете поне един въпрос.';

        foreach ($questions as $idx => $q) {
            $q_content = trim((string) ($q['content'] ?? ''));
            $rawType = (string) ($q['type'] ?? 'single');
            $q_type_ui = in_array($rawType, ['single', 'multiple', 'true_false', 'fill'], true) ? $rawType : 'single';
            $q_points = (float) ($q['points'] ?? 1);
            $ans = $q['answers'] ?? [];

            if ($q_content === '')
                $errors[] = 'Въпрос #' . ($idx + 1) . ': добавете текст на въпроса.';
            if ($q_points < 0) {
                $errors[] = 'Въпрос #' . ($idx + 1) . ': точките не могат да бъдат отрицателни.';
                $q_points = 0;
            }
            $minAnswersRequired = $q_type_ui === 'fill' ? 1 : 2;
            if (!is_array($ans) || count($ans) < $minAnswersRequired) {
                $errors[] = 'Въпрос #' . ($idx + 1) . ': добавете поне ' . ($minAnswersRequired === 1 ? 'един верен отговор.' : 'два отговора.');
                $ans = is_array($ans) ? $ans : [];
            }

            $correctCount = 0;
            $preparedAnswers = [];
            foreach ($ans as $aIdx => $a) {
                $a_content = trim((string) ($a['content'] ?? ''));
                $a_correct = $q_type_ui === 'fill' ? 1 : (!empty($a['is_correct']) ? 1 : 0);
                if ($a_content === '')
                    $errors[] = 'Въпрос #' . ($idx + 1) . ', отговор #' . ($aIdx + 1) . ': добавете текст.';
                if ($a_correct)
                    $correctCount++;
                $preparedAnswers[] = ['content' => $a_content, 'is_correct' => $a_correct];
            }
            if (in_array($q_type_ui, ['single', 'true_false'], true) && $correctCount !== 1)
                $errors[] = 'Въпрос #' . ($idx + 1) . ': изберете точно един верен отговор.';
            elseif ($q_type_ui === 'multiple' && $correctCount === 0)
                $errors[] = 'Въпрос #' . ($idx + 1) . ': маркирайте поне един верен отговор.';
            if ($q_type_ui === 'true_false' && count($preparedAnswers) !== 2)
                $errors[] = 'Въпрос #' . ($idx + 1) . ': Въпросите тип true/false трябва да имат точно два отговора.';
            if ($q_type_ui === 'fill' && count($preparedAnswers) === 0)
                $errors[] = 'Въпрос #' . ($idx + 1) . ': добавете поне един верен отговор.';

            $existingMediaUrl = trim((string) ($q['existing_media_url'] ?? ''));
            $existingMediaMime = trim((string) ($q['existing_media_mime'] ?? ''));
            $removeMedia = !empty($q['remove_media']);

            $mediaUpload = null;
            $mediaUploadMime = null;
            if (is_array($questionMediaFiles)) {
                $candidate = extract_question_media($questionMediaFiles, $idx);
                if ($candidate) {
                    $mediaUploadMime = detect_upload_mime($candidate['tmp_name']) ?: ($candidate['type'] ?? '');
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if ($mediaUploadMime && in_array($mediaUploadMime, $allowedMimes, true)) {
                        $candidate['mime'] = $mediaUploadMime;
                        $mediaUpload = $candidate;
                    } else {
                        $errors[] = 'Въпрос #' . ($idx + 1) . ': изображението трябва да е JPG, PNG, GIF или WEBP.';
                    }
                }
            }

            $processedQuestions[] = [
                'content' => $q_content,
                'type' => $q_type_ui,
                'points' => $q_points,
                'answers' => $preparedAnswers,
                'media_url' => $removeMedia ? '' : $existingMediaUrl,
                'media_mime' => $removeMedia ? '' : $existingMediaMime,
                'existing_media_url' => $existingMediaUrl,
                'existing_media_mime' => $existingMediaMime,
                'remove_media' => $removeMedia ? 1 : 0,
                'media_upload' => $mediaUpload,
                'media_upload_mime' => $mediaUploadMime,
            ];
        }

        $questions = $processedQuestions;

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($editing) {
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

                    $pdo->prepare('DELETE FROM test_questions WHERE test_id = :tid')->execute([':tid' => $test_id]);
                } else {
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

                $order = 1;
                $insQ = $pdo->prepare('INSERT INTO question_bank (owner_teacher_id, visibility, qtype, body) VALUES (:tid, :vis, :qtype, :body)');
                $insA = $pdo->prepare('INSERT INTO answers (question_id, content, is_correct, order_index) VALUES (:qid, :content, :is_correct, :ord)');
                $insLink = $pdo->prepare('INSERT INTO test_questions (test_id, question_id, points, order_index) VALUES (:tid, :qid, :points, :ord)');

                foreach ($questions as $q) {
                    $q_content = trim((string) $q['content']);
                    $q_type_ui = in_array(($q['type'] ?? 'single'), ['single', 'multiple', 'true_false', 'fill'], true) ? $q['type'] : 'single';
                    $q_points = (float) ($q['points'] ?? 1);
                    $answers = $q['answers'];

                    $insQ->execute([
                        ':tid' => $user['id'],
                        ':vis' => $visibility,
                        ':qtype' => map_ui_to_qtype($q_type_ui),
                        ':body' => $q_content,
                    ]);
                    $qid = (int) $pdo->lastInsertId();
                    if ($qid <= 0) {
                        throw new RuntimeException('question_bank AUTO_INCREMENT id missing.');
                    }

                    $mediaUrl = null;
                    $mediaMime = null;
                    if (!empty($q['remove_media'])) {
                        // nothing
                    } elseif (!empty($q['media_upload'])) {
                        $upload = $q['media_upload'];
                        $uploadMime = $q['media_upload_mime'] ?? detect_upload_mime($upload['tmp_name']) ?: ($upload['type'] ?? '');
                        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        if ($uploadMime && in_array($uploadMime, $allowedMimes, true)) {
                            $extension = strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION));
                            if ($extension === '') {
                                if ($uploadMime === 'image/jpeg')
                                    $extension = 'jpg';
                                elseif ($uploadMime === 'image/png')
                                    $extension = 'png';
                                elseif ($uploadMime === 'image/gif')
                                    $extension = 'gif';
                                elseif ($uploadMime === 'image/webp')
                                    $extension = 'webp';
                            }
                            $extension = preg_replace('/[^a-z0-9]/i', '', $extension);
                            if ($extension === '' || $extension === null) {
                                $extension = 'jpg';
                            }
                            $dir = __DIR__ . '/uploads';
                            if (!is_dir($dir)) {
                                @mkdir($dir, 0777, true);
                            }
                            $filename = 'question_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                            if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $filename)) {
                                throw new RuntimeException('Неуспешно записване на каченото изображение.');
                            }
                            $mediaUrl = 'uploads/' . $filename;
                            $mediaMime = $uploadMime;
                        }
                    } elseif (!empty($q['media_url'])) {
                        $mediaUrl = $q['media_url'];
                        $mediaMime = $q['media_mime'] ?? null;
                    }

                    if ($mediaUrl) {
                        $pdo->prepare('UPDATE question_bank SET media_url = :url, media_mime = :mime WHERE id = :id')
                            ->execute([
                                ':url' => $mediaUrl,
                                ':mime' => $mediaMime,
                                ':id' => $qid,
                            ]);
                    }

                    $aOrder = 1;
                    foreach ($answers as $a) {
                        $insA->execute([
                            ':qid' => $qid,
                            ':content' => trim((string) $a['content']),
                            ':is_correct' => !empty($a['is_correct']) ? 1 : 0,
                            ':ord' => $aOrder++,
                        ]);
                    }

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

        /* Тъмен card стил */
        .card-dark .card-header {
            background: #0f172a;
            color: #fff;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .card-dark .card-body {
            background: #0b1220;
            color: #e5e7eb;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .card-dark .form-label {
            color: #cbd5e1;
        }

        .card-dark .form-control,
        .card-dark .form-select {
            background: #0f172a;
            color: #e5e7eb;
            border: 1px solid #334155;
        }

        .card-dark .form-control::file-selector-button {
            background: #111827;
            color: #e5e7eb;
            border: 1px solid #334155;
        }

        .card-dark .form-control:focus,
        .card-dark .form-select:focus {
            background: #0f172a;
            color: #fff;
            border-color: #60a5fa;
            box-shadow: 0 0 0 .25rem rgba(59, 130, 246, .25);
        }

        .card-dark .text-hint {
            color: #93c5fd;
        }

        .card-dark .btn-upload {
            border-color: #93c5fd;
            color: #93c5fd;
        }

        .card-dark .btn-upload:hover {
            background: #1e293b;
            color: #fff;
            border-color: #60a5fa;
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

        <?php if ($importNotice): ?>
            <div class="alert alert-info"><?= htmlspecialchars($importNotice) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="card card-dark mb-4" id="testForm">
            <div class="card-header"><strong>Основни данни</strong></div>
            <div class="card-body mb-4">
                <h5 class="h6 mb-3">Можете да заредите въпрости от таблица в excel</h5>
                <div class="row g-3 align-items-start">
                    <div class="col-md-6">
                        <label for="excel_file" class="form-label">Избор на файл (.xlsx)</label>
                        <input id="excel_file" type="file" name="excel_file" class="form-control" accept=".xlsx" />
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex flex-wrap gap-2">
                            <!-- formnovalidate: заобикаля HTML5 required на празните полета -->
                            <button type="submit" name="import_excel" value="1"
                                class="btn btn-outline-primary btn-upload" formnovalidate>
                                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Зареди от Excel
                            </button>
                            <a href="download_template.php" class="btn btn-outline-primary btn-upload">
                                <i class="bi bi-download me-1"></i> Изтеглете темплейт (.xlsx)
                            </a>
                        </div>
                        <div class="small text-hint mt-2">
                            Качете файла след като попълните таблицата в excel:
                            <em>Question, Type, Points, Answer 1…Answer N, Correct</em>
                            (използвайте <em>1</em> or <em>1,3</em>).
                        </div>
                    </div>
                </div>
            </div>
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
                        <label class="form-check-label" for="is_strict_mode">Стриктен режим (при напускане опитът се
                            анулира)</label>
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
                                        <select name="questions[<?= $qi ?>][type]" class="form-select" data-q-type>
                                            <option value="single" <?= ($q['type'] === 'single') ? 'selected' : '' ?>>Единичен
                                                избор</option>
                                            <option value="multiple" <?= ($q['type'] === 'multiple') ? 'selected' : '' ?>>
                                                Множествен избор</option>
                                            <option value="true_false" <?= ($q['type'] === 'true_false') ? 'selected' : '' ?>>
                                                Вярно / грешно</option>
                                            <option value="fill" <?= ($q['type'] === 'fill') ? 'selected' : '' ?>>
                                                Попълни липсващите думи</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Точки</label>
                                        <input type="number" step="0.01" name="questions[<?= $qi ?>][points]"
                                            class="form-control" min="0" value="<?= (float) $q['points'] ?>" />
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Изображение (по желание)</label>
                                    <?php if (!empty($q['media_url'])): ?>
                                        <div class="question-media-preview mb-2" data-media-preview>
                                            <img src="<?= htmlspecialchars($q['media_url']) ?>" alt="Media preview"
                                                class="img-fluid rounded border">
                                        </div>
                                        <div class="form-check mb-2">
                                            <label class="form-check-label">
                                                <input class="form-check-input me-1" type="checkbox"
                                                    name="questions[<?= $qi ?>][remove_media]" value="1" data-remove-media>
                                                Премахни текущото изображение
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" class="form-control" name="question_media[<?= $qi ?>]" accept="image/*"
                                        data-media-input />
                                    <input type="hidden" name="questions[<?= $qi ?>][existing_media_url]"
                                        value="<?= htmlspecialchars($q['media_url'] ?? '') ?>" data-existing-url>
                                    <input type="hidden" name="questions[<?= $qi ?>][existing_media_mime]"
                                        value="<?= htmlspecialchars($q['media_mime'] ?? '') ?>" data-existing-mime>
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
                                    <select name="questions[0][type]" class="form-select" data-q-type>
                                        <option value="single">Единичен избор</option>
                                        <option value="multiple">Множествен избор</option>
                                        <option value="true_false">Вярно / грешно</option>
                                        <option value="fill">Попълни липсващите думи</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Точки</label>
                                    <input type="number" step="0.01" name="questions[0][points]" class="form-control"
                                        min="0" value="1" />
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Изображение (по желание)</label>
                                <input type="file" class="form-control" name="question_media[0]" accept="image/*"
                                    data-media-input />
                                <input type="hidden" name="questions[0][existing_media_url]" value="" data-existing-url>
                                <input type="hidden" name="questions[0][existing_media_mime]" value="" data-existing-mime>
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

                <button type="button" class="btn btn-outline-primary" id="addQuestionBtn" onclick="addQuestion()">Добави въпрос</button>
            </div>

            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <button type="submit" name="save_test" value="1" class="btn btn-primary"><i
                        class="bi bi-check2-circle me-1"></i>Запази теста</button>
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
            function getQuestionType(qEl) {
                const select = qEl ? qEl.querySelector('[data-q-type]') : null;
                return select ? select.value : 'single';
            }
            function getQuestionIndex(qEl) {
                return qEl ? Array.from(document.querySelectorAll('[data-q]')).indexOf(qEl) : -1;
            }
            function applyAnswerRowMode(row, type) {
                if (!row)
                    return;
                const checkWrap = row.querySelector('.form-check');
                const checkbox = checkWrap ? checkWrap.querySelector('input[type="checkbox"]') : null;
                if (type === 'fill') {
                    if (checkWrap)
                        checkWrap.classList.add('d-none');
                    if (checkbox)
                        checkbox.checked = true;
                } else {
                    if (checkWrap)
                        checkWrap.classList.remove('d-none');
                }
            }
            function appendAnswerRow(answersWrap, qi, ai, content = '', isCorrect = false) {
                if (!answersWrap || qi < 0 || ai < 0)
                    return;
                const row = document.createElement('div');
                row.className = 'answer-row';
                row.setAttribute('data-answer', '');
                row.innerHTML = `
                    <input type="text" class="form-control" name="questions[${qi}][answers][${ai}][content]" placeholder="Отговор..." required />
                    <div class="form-check d-flex align-items-center">
                        <input class="form-check-input" type="checkbox" name="questions[${qi}][answers][${ai}][is_correct]" />
                        <label class="form-check-label ms-1">Верен</label>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="rmAnswer(this)"><i class="bi bi-x"></i></button>
                `;
                const textInput = row.querySelector('input[type="text"]');
                if (textInput)
                    textInput.value = content;
                const checkbox = row.querySelector('input[type="checkbox"]');
                if (checkbox)
                    checkbox.checked = !!isCorrect;
                answersWrap.appendChild(row);
                const qEl = answersWrap.closest('[data-q]');
                applyAnswerRowMode(row, getQuestionType(qEl));
            }
            function ensureTrueFalseAnswers(qEl, shouldRenumber = true) {
                if (!qEl)
                    return;
                const answersWrap = qEl.querySelector('[data-answers]');
                if (!answersWrap)
                    return;
                const qi = getQuestionIndex(qEl);
                if (qi < 0)
                    return;
                let rows = answersWrap.querySelectorAll('[data-answer]');
                while (rows.length > 2) {
                    rows[rows.length - 1].remove();
                    rows = answersWrap.querySelectorAll('[data-answer]');
                }
                const defaults = ['Вярно', 'Грешно'];
                while (rows.length < 2) {
                    appendAnswerRow(answersWrap, qi, rows.length);
                    rows = answersWrap.querySelectorAll('[data-answer]');
                }
                rows = answersWrap.querySelectorAll('[data-answer]');
                rows.forEach((row, idx) => {
                    const input = row.querySelector('input[type="text"]');
                    if (input && input.value.trim() === '' && defaults[idx])
                        input.value = defaults[idx];
                });
                const hasCorrect = Array.from(rows).some(row => {
                    const checkbox = row.querySelector('input[type="checkbox"]');
                    return checkbox && checkbox.checked;
                });
                if (!hasCorrect && rows[0]) {
                    const checkbox = rows[0].querySelector('input[type="checkbox"]');
                    if (checkbox)
                        checkbox.checked = true;
                }
                rows.forEach(row => applyAnswerRowMode(row, 'true_false'));
                if (shouldRenumber)
                    renumber();
            }
            function syncQuestionUI(qEl, options = {}) {
                if (!qEl)
                    return;
                const { renumberAfter = true } = options;
                const type = getQuestionType(qEl);
                if (type === 'true_false')
                    ensureTrueFalseAnswers(qEl, false);
                const answersWrap = qEl.querySelector('[data-answers]');
                if (answersWrap) {
                    const qi = getQuestionIndex(qEl);
                    if (type === 'fill' && answersWrap.querySelectorAll('[data-answer]').length === 0)
                        appendAnswerRow(answersWrap, qi, 0);
                    answersWrap.querySelectorAll('[data-answer]').forEach(row => applyAnswerRowMode(row, type));
                }
                if (renumberAfter)
                    renumber();
            }
            function handleTypeChange(selectEl) {
                if (!selectEl)
                    return;
                const qEl = selectEl.closest('[data-q]');
                if (!qEl)
                    return;
                syncQuestionUI(qEl);
            }
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
                    const mediaInput = qEl.querySelector('[data-media-input]');
                    if (mediaInput) {
                        mediaInput.name = 'question_media[' + qi + ']';
                    }
                });
            }
            function addQuestion() {
                const wrap = document.getElementById('questions');
                const tmpl = wrap.querySelector('[data-q]').cloneNode(true);
                tmpl.querySelectorAll('input[type="text"]').forEach(i => i.value = '');
                tmpl.querySelectorAll('textarea').forEach(i => i.value = '');
                tmpl.querySelectorAll('input[type="number"]').forEach(i => i.value = '1');
                tmpl.querySelectorAll('input[type="checkbox"]').forEach(i => i.checked = false);
                tmpl.querySelectorAll('[data-existing-url]').forEach(i => i.value = '');
                tmpl.querySelectorAll('[data-existing-mime]').forEach(i => i.value = '');
                tmpl.querySelectorAll('[data-remove-media]').forEach(i => i.checked = false);
                tmpl.querySelectorAll('[data-media-input]').forEach(i => i.value = '');
                tmpl.querySelectorAll('[data-media-preview]').forEach(el => el.remove());
                const typeSelect = tmpl.querySelector('[data-q-type]');
                if (typeSelect)
                    typeSelect.value = 'single';
                const answersWrap = tmpl.querySelector('[data-answers]');
                if (answersWrap) {
                    answersWrap.innerHTML = '';
                    appendAnswerRow(answersWrap, 0, 0);
                    appendAnswerRow(answersWrap, 0, 1);
                }
                wrap.appendChild(tmpl);
                syncQuestionUI(tmpl, { renumberAfter: false });
                renumber();
            }
            function rmQuestion(btn) {
                const card = btn.closest('[data-q]');
                const wrap = document.getElementById('questions');
                if (wrap.querySelectorAll('[data-q]').length <= 1) {
                    alert('Не може да изтриете последния въпрос.');
                    return;
                }
                card.remove();
                renumber();
            }
            function addAnswer(btn) {
                const qEl = btn.closest('[data-q]');
                if (!qEl)
                    return;
                if (getQuestionType(qEl) === 'true_false') {
                    alert('Въпросите тип true/false имат фиксирани два отговора.');
                    return;
                }
                const answersWrap = qEl.querySelector('[data-answers]');
                if (!answersWrap)
                    return;
                const qi = getQuestionIndex(qEl);
                const ai = answersWrap.querySelectorAll('[data-answer]').length;
                appendAnswerRow(answersWrap, qi, ai);
            }
            function rmAnswer(btn) {
                const answersWrap = btn.closest('[data-answers]');
                if (!answersWrap)
                    return;
                const qEl = btn.closest('[data-q]');
                const type = getQuestionType(qEl);
                if (type === 'true_false') {
                    alert('Въпросите тип true/false трябва да имат точно два отговора.');
                    return;
                }
                const minAnswers = type === 'fill' ? 1 : 2;
                if (answersWrap.querySelectorAll('[data-answer]').length <= minAnswers) {
                    if (type === 'fill')
                        alert('Въпросите тип "Попълни липсващите думи" изискват поне един верен отговор.');
                    else
                        alert('Трябва да оставите поне два отговора.');
                    return;
                }
                btn.closest('[data-answer]').remove();
                renumber();
            }
            document.addEventListener('change', function (event) {
                if (event.target.matches('[data-q-type]'))
                    handleTypeChange(event.target);
            });
            document.querySelectorAll('[data-q-type]').forEach(select => {
                const qEl = select.closest('[data-q]');
                syncQuestionUI(qEl, { renumberAfter: false });
            });
        </script>
    </footer>
</body>

</html>
