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

// Load subjects for selector
$subjects = [];
try {
    $stmt = $pdo->prepare('SELECT id, name FROM subjects WHERE owner_teacher_id = :tid ORDER BY name');
    $stmt->execute([':tid'=>$user['id']]);
    $subjects = $stmt->fetchAll();
} catch (Throwable $e) {
    $subjects = [];
}

$errors = [];
$success = null;

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
    $theme = $_POST['theme'] ?? 'default';
    $status = in_array(($_POST['status'] ?? 'draft'), ['draft','published','archived'], true) ? $_POST['status'] : 'draft';
    $time_limit_sec = (isset($_POST['time_limit_sec']) && $_POST['time_limit_sec'] !== '') ? max(0, (int)$_POST['time_limit_sec']) : null;
    $is_randomized = !empty($_POST['is_randomized']) ? 1 : 0;

    $questions = $_POST['questions'] ?? [];

    if ($title === '') { $errors[] = 'Моля, въведете заглавие на теста.'; }
    if (empty($questions)) { $errors[] = 'Добавете поне един въпрос.'; }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Create test
            $theme_cfg_json = ($theme === 'custom' && !empty($_POST['theme_cfg'])) ? json_encode($_POST['theme_cfg']) : null;
            $stmt = $pdo->prepare('INSERT INTO tests (owner_teacher_id, subject_id, title, description, visibility, status, time_limit_sec, is_randomized, theme, theme_config)
                                   VALUES (:owner, :subject_id, :title, :description, :visibility, :status, :time_limit_sec, :is_randomized, :theme, :theme_config)');
            $stmt->execute([
                ':owner' => (int)$user['id'],
                ':subject_id' => $subject_id,
                ':title' => $title,
                ':description' => $description,
                ':visibility' => $visibility,
                ':status' => $status,
                ':time_limit_sec' => $time_limit_sec,
                ':is_randomized' => $is_randomized,
                ':theme' => $theme,
                ':theme_config' => $theme_cfg_json,
            ]);
            $test_id = (int)$pdo->lastInsertId();

            $order_index = 0;
            foreach ($questions as $q) {
                $order_index++;
                $qtype = $q['qtype'] ?? 'single_choice';
                if (!in_array($qtype, ['single_choice','multiple_choice','true_false','short_answer','numeric'], true)) {
                    $qtype = 'single_choice';
                }
                $body = trim((string)($q['body'] ?? ''));
                $explanation = trim((string)($q['explanation'] ?? '')) ?: null;
                $difficulty = ($q['difficulty'] ?? '') !== '' ? max(1, min(5, (int)$q['difficulty'])) : null;
                $points = ($q['points'] ?? '') !== '' ? (float)$q['points'] : 1.0;

                if ($body === '') { throw new RuntimeException('Липсва съдържание на въпрос.'); }

                // Insert into question_bank
                $stmt = $pdo->prepare('INSERT INTO question_bank (owner_teacher_id, visibility, qtype, body, explanation, difficulty)
                                       VALUES (:owner, :visibility, :qtype, :body, :explanation, :difficulty)');
                $stmt->execute([
                    ':owner' => (int)$user['id'],
                    ':visibility' => $visibility,
                    ':qtype' => $qtype,
                    ':body' => $body,
                    ':explanation' => $explanation,
                    ':difficulty' => $difficulty,
                ]);
                $question_id = (int)$pdo->lastInsertId();

                // Handle optional media upload aligned by question index
                $index = $order_index - 1;
                if (!empty($_FILES['qfile']) && isset($_FILES['qfile']['error'][$index]) && $_FILES['qfile']['error'][$index] === UPLOAD_ERR_OK) {
                    $allowed = ['image/jpeg','image/png','image/gif','application/pdf'];
                    $mime = mime_content_type($_FILES['qfile']['tmp_name'][$index]);
                    if (in_array($mime, $allowed, true)) {
                        $ext = pathinfo($_FILES['qfile']['name'][$index], PATHINFO_EXTENSION);
                        $name = 'q_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $dir = __DIR__ . '/uploads'; if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                        if (move_uploaded_file($_FILES['qfile']['tmp_name'][$index], $dir . '/' . $name)) {
                            $rel = 'uploads/' . $name;
                            $upd = $pdo->prepare('UPDATE question_bank SET media_url = :url, media_mime = :mime WHERE id = :id');
                            $upd->execute([':url'=>$rel, ':mime'=>$mime, ':id'=>$question_id]);
                        }
                    }
                }

                // Answers depending on type
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
                        $stmt = $pdo->prepare('INSERT INTO answers (question_id, content, is_correct, order_index) VALUES (:qid, :content, :is_correct, :order_index)');
                        $stmt->execute([
                            ':qid' => $question_id,
                            ':content' => $content,
                            ':is_correct' => $is_correct,
                            ':order_index' => $order,
                        ]);
                    }
                    if (!$hasCorrect) {
                        throw new RuntimeException('Няма маркиран верен отговор при затворен въпрос.');
                    }
                } elseif ($qtype === 'short_answer') {
                    $answers_line = trim((string)($q['short_answers'] ?? ''));
                    if ($answers_line !== '') {
                        $accepted = array_filter(array_map('trim', preg_split('/\r?\n|\|/', $answers_line)));
                        $order = 0;
                        foreach ($accepted as $ans) {
                            $order++;
                            $stmt = $pdo->prepare('INSERT INTO answers (question_id, content, is_correct, order_index) VALUES (:qid, :content, 1, :order_index)');
                            $stmt->execute([
                                ':qid' => $question_id,
                                ':content' => $ans,
                                ':order_index' => $order,
                            ]);
                        }
                    }
                } elseif ($qtype === 'numeric') {
                    $num = trim((string)($q['numeric_answer'] ?? ''));
                    if ($num !== '') {
                        $stmt = $pdo->prepare('INSERT INTO answers (question_id, content, is_correct, order_index) VALUES (:qid, :content, 1, 1)');
                        $stmt->execute([
                            ':qid' => $question_id,
                            ':content' => $num,
                        ]);
                    }
                }

                // Map into test
                $stmt = $pdo->prepare('INSERT INTO test_questions (test_id, question_id, points, order_index) VALUES (:test_id, :question_id, :points, :order_index)');
                $stmt->execute([
                    ':test_id' => $test_id,
                    ':question_id' => $question_id,
                    ':points' => $points,
                    ':order_index' => $order_index,
                ]);
            }

            $pdo->commit();
            header('Location: dashboard.php?created_test=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Грешка при запис: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Нов тест – TestGramatikov</title>
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
        <h1 class="h4 m-0"><i class="bi bi-magic me-2"></i>Създаване на нов тест</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="m-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" id="testForm" enctype="multipart/form-data">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Данни за теста</strong></div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Заглавие</label>
                    <input type="text" name="title" class="form-control" required />
                </div>
                <div class="col-md-3">
                    <label class="form-label">Предмет</label>
                    <select name="subject_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Видимост</label>
                    <select name="visibility" class="form-select">
                        <option value="private">Само аз</option>
                        <option value="shared">Споделен</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Цветова схема</label>
                    <select name="theme" class="form-select" id="themeSelect">
                        <option value="default">По подразбиране</option>
                        <option value="soft">Мека (бежова)</option>
                        <option value="dark">Тъмна</option>
                        <option value="ocean">Океания</option>
                        <option value="orange">Ориндж</option>
                        <option value="forest">Гора</option>
                        <option value="berry">Горски плод</option>
                        <option value="custom">Къстъм</option>
                    </select>
                </div>
                <div class="col-12" id="customThemeWrap" style="display:none;">
                    <div class="row g-2">
                        <div class="col-md-2"><label class="form-label small">Основен цвят</label><input type="color" class="form-control form-control-color" name="theme_cfg[primary]" value="#0d6efd"></div>
                        <div class="col-md-2"><label class="form-label small">Фон</label><input type="color" class="form-control form-control-color" name="theme_cfg[bg]" value="#ffffff"></div>
                        <div class="col-md-2"><label class="form-label small">Карта</label><input type="color" class="form-control form-control-color" name="theme_cfg[card]" value="#ffffff"></div>
                        <div class="col-md-2"><label class="form-label small">Хедър</label><input type="color" class="form-control form-control-color" name="theme_cfg[header]" value="#e9ecef"></div>
                        <div class="col-md-2"><label class="form-label small">Текст</label><input type="color" class="form-control form-control-color" name="theme_cfg[text]" value="#212529"></div>
                        <div class="col-md-2"><label class="form-label small">Бутон</label><input type="color" class="form-control form-control-color" name="theme_cfg[button]" value="#0d6efd"></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Статус</label>
                    <select name="status" class="form-select">
                        <option value="draft">Чернова</option>
                        <option value="published">Публикуван</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Лимит (секунди)</label>
                    <input type="number" name="time_limit_sec" class="form-control" min="0" placeholder="без лимит" />
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_randomized" name="is_randomized" />
                        <label class="form-check-label" for="is_randomized">Разбъркване на въпросите</label>
                    </div>
                </div>
            </div>
        </div>

        <div id="questionsContainer"></div>

        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary" id="addQuestionBtn"><i class="bi bi-plus-lg me-1"></i>Добави въпрос</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Запази теста</button>
        </div>
    </form>
</main>

<template id="questionTemplate">
    <div class="card shadow-sm mb-3 q-card question-block">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex gap-2 align-items-center">
                    <select class="form-select form-select-sm qtype" style="width:auto">
                        <option value="single_choice">Един верен</option>
                        <option value="multiple_choice">Повече от един верен</option>
                        <option value="true_false">Вярно/Грешно</option>
                        <option value="short_answer">Отворен отговор</option>
                        <option value="numeric">Числен отговор</option>
                    </select>
                    <input type="number" class="form-control form-control-sm points" min="0" step="0.5" value="1" style="width:120px" title="Точки" />
                    <select class="form-select form-select-sm difficulty" style="width:auto">
                        <option value="">Трудност</option>
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
                <textarea class="form-control body" rows="2" placeholder="Текст на въпроса..."></textarea>
            </div>
            <div class="mb-2">
                <label class="form-label small">Медия (изображение/PDF, по желание)</label>
                <input type="file" class="form-control form-control-sm qfile" name="qfile[]" accept="image/*,application/pdf" />
            </div>
            <div class="options-wrap"></div>
            <div class="mt-2">
                <input type="text" class="form-control form-control-sm explanation" placeholder="Обяснение (по желание)" />
            </div>
        </div>
    </div>
</template>

<template id="optionRowTemplate">
    <div class="d-flex option-row align-items-center mb-2">
        <input type="text" class="form-control option-content" placeholder="Възможен отговор" />
        <div class="form-check ms-1 me-1">
            <input class="form-check-input option-correct" type="checkbox" />
            <label class="form-check-label">верен</label>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary remove-option"><i class="bi bi-x"></i></button>
    </div>
</template>

<script>
const container = document.getElementById('questionsContainer');
const qTpl = document.getElementById('questionTemplate');
const optTpl = document.getElementById('optionRowTemplate');
const addBtn = document.getElementById('addQuestionBtn');

function renderOptions(block, type){
  const wrap = block.querySelector('.options-wrap');
  wrap.innerHTML = '';
  if (type === 'single_choice' || type === 'multiple_choice') {
    const addOpt = document.createElement('button');
    addOpt.type = 'button';
    addOpt.className = 'btn btn-sm btn-outline-primary mb-2';
    addOpt.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Добави отговор';
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
          <input class="form-check-input tf-correct" type="radio" name="tf-${Date.now()}" value="true"> <label class="form-check-label">Вярно</label>
        </div>
        <div class="form-check">
          <input class="form-check-input tf-correct" type="radio" name="tf-${Date.now()}" value="false"> <label class="form-check-label">Грешно</label>
        </div>
      </div>`;
  } else if (type === 'short_answer') {
    wrap.innerHTML = '<textarea class="form-control short-answers" rows="2" placeholder="Допустими отговори (всеки на нов ред или разделени с |)"></textarea>';
  } else if (type === 'numeric') {
    wrap.innerHTML = '<input type="number" step="any" class="form-control numeric-answer" placeholder="Верният числен отговор" />';
  }
}

function addQuestion(){
  const frag = qTpl.content.cloneNode(true);
  const block = frag.querySelector('.question-block');
  const select = block.querySelector('.qtype');
  renderOptions(block, select.value);
  select.addEventListener('change', ()=> renderOptions(block, select.value));
  block.querySelector('.remove-question').addEventListener('click', ()=> block.remove());
  container.appendChild(frag);
}

addBtn.addEventListener('click', addQuestion);
// Add initial question
addQuestion();

// On submit, serialize dynamic controls into questions[*]
document.getElementById('testForm').addEventListener('submit', function(e){
  const qBlocks = container.querySelectorAll('.question-block');
  if (!qBlocks.length){
    alert('Добавете поне един въпрос.');
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

    // Hidden inputs
    const addHidden = (name, value) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = `questions[${i}][${name}]`;
      input.value = value;
      e.target.appendChild(input);
    };

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
        const c = document.createElement('input');
        c.type = 'hidden'; c.name = `questions[${i}][options][${j}][content]`; c.value = content; e.target.appendChild(c);
        const k = document.createElement('input');
        k.type = 'hidden'; k.name = `questions[${i}][options][${j}][is_correct]`; k.value = correct; e.target.appendChild(k);
        j++;
      });
    } else if (qtype === 'true_false') {
      const selected = block.querySelector('.tf-correct:checked');
      if (selected){
        const val = selected.value === 'true' ? 'Вярно' : 'Грешно';
        const c = document.createElement('input'); c.type='hidden'; c.name=`questions[${i}][options][0][content]`; c.value='Вярно'; e.target.appendChild(c);
        const k = document.createElement('input'); k.type='hidden'; k.name=`questions[${i}][options][0][is_correct]`; k.value=(val==='Вярно')?'1':''; e.target.appendChild(k);
        const c2 = document.createElement('input'); c2.type='hidden'; c2.name=`questions[${i}][options][1][content]`; c2.value='Грешно'; e.target.appendChild(c2);
        const k2 = document.createElement('input'); k2.type='hidden'; k2.name=`questions[${i}][options][1][is_correct]`; k2.value=(val==='Грешно')?'1':''; e.target.appendChild(k2);
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

// Toggle custom theme controls
const themeSelect = document.getElementById('themeSelect');
const customWrap = document.getElementById('customThemeWrap');
themeSelect.addEventListener('change', ()=>{
  customWrap.style.display = themeSelect.value === 'custom' ? '' : 'none';
});
</script>

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
