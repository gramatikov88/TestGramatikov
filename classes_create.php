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

function random_password($length = 10): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

$class_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($class_id === 0 && isset($_POST['id'])) { // fallback, ако сървърът е изрязал query string при POST
    $class_id = (int)$_POST['id'];
}
$editing = $class_id > 0;

$errors = [];
$saved = false;
$created_accounts = [];

// Зареждане на класа при редакция
$class = null;
if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = :id AND teacher_id = :tid');
    $stmt->execute([':id' => $class_id, ':tid' => (int)$user['id']]);
    $class = $stmt->fetch();
    if (!$class) { $errors[] = 'Класът не е намерен или нямате достъп.'; $editing = false; $class_id = 0; }
}

// Запис на клас (и едновременно добавяне на ученици)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'save_class') {
    $name = trim((string)($_POST['name'] ?? ''));
    $grade = max(1, (int)($_POST['grade'] ?? 1));
    $section = trim((string)($_POST['section'] ?? ''));
    $school_year = (int)($_POST['school_year'] ?? date('Y'));
    $description = trim((string)($_POST['description'] ?? ''));

    $draft_students_json = (string)($_POST['draft_students'] ?? '');
    $draft_students = [];
    if ($draft_students_json !== '') {
        $tmp = json_decode($draft_students_json, true);
        if (is_array($tmp)) { $draft_students = $tmp; } else { $errors[] = 'Невалидни данни за учениците.'; }
    }

    if ($name === '') $errors[] = 'Моля, въведете име на клас.';
    if ($section === '') $errors[] = 'Моля, въведете паралелка (буква).';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            if ($editing) {
                $stmt = $pdo->prepare('UPDATE classes SET name=:name, grade=:grade, section=:section, school_year=:sy, description=:desc WHERE id=:id AND teacher_id=:tid');
                $stmt->execute([
                    ':name'=>$name, ':grade'=>$grade, ':section'=>$section, ':sy'=>$school_year, ':desc'=>$description,
                    ':id'=>$class_id, ':tid'=>(int)$user['id']
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO classes (teacher_id, name, grade, section, school_year, description) VALUES (:tid,:name,:grade,:section,:sy,:desc)');
                $stmt->execute([
                    ':tid'=>(int)$user['id'], ':name'=>$name, ':grade'=>$grade, ':section'=>$section, ':sy'=>$school_year, ':desc'=>$description
                ]);
                $class_id = (int)$pdo->lastInsertId();
                $editing = true;
                $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = :id AND teacher_id = :tid');
                $stmt->execute([':id' => $class_id, ':tid' => (int)$user['id']]);
                $class = $stmt->fetch();
            }

            // Добавяне/създаване на ученици от черновата
            if ($class_id && $draft_students) {
                foreach ($draft_students as $ds) {
                    if (isset($ds['id']) && (int)$ds['id'] > 0) {
                        $sid = (int)$ds['id'];
                        $chk = $pdo->prepare('SELECT 1 FROM users WHERE id = :id AND role = "student"');
                        $chk->execute([':id'=>$sid]);
                        if ($chk->fetchColumn()) {
                            $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid,:sid)')->execute([':cid'=>$class_id, ':sid'=>$sid]);
                        }
                        continue;
                    }
                    $email = isset($ds['email']) ? mb_strtolower(trim((string)$ds['email'])) : '';
                    $first = trim((string)($ds['first_name'] ?? ''));
                    $last  = trim((string)($ds['last_name'] ?? ''));
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $first === '' || $last === '') { continue; }
                    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1');
                    $stmt->execute([':email'=>$email]);
                    $u = $stmt->fetch();
                    if ($u) {
                        if ($u['role'] !== 'student') { continue; }
                        $sid = (int)$u['id'];
                    } else {
                        $pwd = random_password(10);
                        $hash = password_hash($pwd, PASSWORD_DEFAULT);
                        $pdo->prepare('INSERT INTO users (role, email, password_hash, first_name, last_name) VALUES ("student", :email, :hash, :first, :last)')->execute([
                            ':email'=>$email, ':hash'=>$hash, ':first'=>$first, ':last'=>$last
                        ]);
                        $sid = (int)$pdo->lastInsertId();
                        $created_accounts[] = ['email'=>$email, 'password'=>$pwd, 'first_name'=>$first, 'last_name'=>$last];
                    }
                    $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid,:sid)')->execute([':cid'=>$class_id, ':sid'=>$sid]);
                }
            }

            $pdo->commit();
            $saved = true;
            header('Location: classes_create.php?id=' . $class_id . '#students');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $__) {} }
            if ($e->getCode() === '23000') {
                $errors[] = 'Този клас вече съществува (учител + клас + паралелка + година).';
            } else {
                $errors[] = 'Възникна грешка при записа: ' . $e->getMessage();
            }
        }
    }
}

// Премахване на ученик от класа
if ($editing && isset($_GET['remove_student'])) {
    $sid = (int)$_GET['remove_student'];
    $pdo->prepare('DELETE cs FROM class_students cs JOIN classes c ON c.id = cs.class_id AND c.teacher_id = :tid WHERE cs.class_id = :cid AND cs.student_id = :sid')
        ->execute([':tid'=>(int)$user['id'], ':cid'=>$class_id, ':sid'=>$sid]);
    header('Location: classes_create.php?id=' . $class_id . '#students');
    exit;
}

// Изтриване на клас
if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'delete_class') {
    try {
        $pdo->prepare('DELETE FROM classes WHERE id = :id AND teacher_id = :tid')->execute([':id'=>$class_id, ':tid'=>(int)$user['id']]);
        header('Location: dashboard.php');
        exit;
    } catch (Throwable $e) {
        $errors[] = 'Неуспешно изтриване на класа.';
    }
}

// Зареждане на учениците в класа
$students = [];
if ($editing) {
    $stmt = $pdo->prepare('SELECT u.id, u.first_name, u.last_name, u.email FROM class_students cs JOIN users u ON u.id = cs.student_id WHERE cs.class_id = :cid ORDER BY u.first_name, u.last_name');
    $stmt->execute([':cid'=>$class_id]);
    $students = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= $editing ? 'Редакция на клас' : 'Нов клас' ?> — TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .scroll-area { max-height: 320px; overflow: auto; }
    </style>
    <!-- Страница и текстове са в UTF-8 -->
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 m-0"><?= $editing ? 'Редакция на клас' : 'Създаване на клас' ?></h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Табло</a>
    </div>

    <?php if ($saved): ?><div class="alert alert-success">Промените са записани успешно.</div><?php endif; ?>
    <?php if ($created_accounts): ?>
        <div class="alert alert-info">
            Създадени ученици:
            <ul class="m-0 ps-3">
                <?php foreach ($created_accounts as $ca): ?>
                    <li><?= htmlspecialchars($ca['first_name'].' '.$ca['last_name'].' ('.$ca['email'].')') ?> — парола: <strong><?= htmlspecialchars($ca['password']) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="m-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form method="post" class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Данни за класа</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">Име</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($class['name'] ?? '') ?>" required />
            </div>
            <div class="col-md-2">
                <label class="form-label">Клас</label>
                <input type="number" name="grade" class="form-control" min="1" max="12" value="<?= htmlspecialchars($class['grade'] ?? '') ?>" required />
            </div>
            <div class="col-md-2">
                <label class="form-label">Паралелка</label>
                <input type="text" name="section" class="form-control" maxlength="5" value="<?= htmlspecialchars($class['section'] ?? '') ?>" required />
            </div>
            <div class="col-md-2">
                <label class="form-label">Учебна година</label>
                <input type="number" name="school_year" class="form-control" min="2000" max="2100" value="<?= htmlspecialchars($class['school_year'] ?? date('Y')) ?>" required />
            </div>
            <div class="col-12">
                <label class="form-label">Описание</label>
                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($class['description'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end">
            <input type="hidden" name="id" value="<?= (int)$class_id ?>" />
            <input type="hidden" name="draft_students" id="draft_students" value="<?= htmlspecialchars($_POST['draft_students'] ?? '[]') ?>" />
            <input type="hidden" name="__action" value="save_class" />
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Запази</button>
        </div>
    </form>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Ученици към класа</strong> <span class="badge bg-light text-dark ms-2">добавянето става при „Запази“</span></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Търсене на ученик</label>
                    <select id="student_search" class="form-select" style="width:100%"></select>
                    <button class="btn btn-outline-primary mt-2" type="button" id="addSelected"><i class="bi bi-person-plus"></i> Добави избрания</button>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Създаване на нов ученик</label>
                    <div class="row g-2">
                        <div class="col-12 col-md-6"><input type="email" id="new_email" class="form-control" placeholder="email@domain.com" /></div>
                        <div class="col-6 col-md-3"><input type="text" id="new_first" class="form-control" placeholder="Име" /></div>
                        <div class="col-6 col-md-3"><input type="text" id="new_last" class="form-control" placeholder="Фамилия" /></div>
                    </div>
                    <button class="btn btn-outline-secondary mt-2" type="button" id="addManual"><i class="bi bi-plus-lg"></i> Добави в списъка</button>
                </div>
                <div class="col-12">
                    <div id="draft_list" class="list-group list-group-flush border rounded"></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($editing): ?>
    <a id="students"></a>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Записани ученици</strong></div>
                <div class="list-group list-group-flush scroll-area">
                    <?php if (!$students): ?><div class="list-group-item text-muted">Няма записани ученици.</div><?php endif; ?>
                    <?php foreach ($students as $s): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($s['email']) ?></div>
                            </div>
                            <a class="btn btn-sm btn-outline-danger" href="classes_create.php?id=<?= (int)$class_id ?>&remove_student=<?= (int)$s['id'] ?>" onclick="return confirm('Премахване на ученика от класа?');"><i class="bi bi-x"></i></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Опасни действия</strong></div>
                <div class="card-body">
                    <form method="post" onsubmit="return confirm('Сигурни ли сте, че искате да изтриете този клас? Действието е необратимо.');">
                        <input type="hidden" name="id" value="<?= (int)$class_id ?>" />
                        <input type="hidden" name="__action" value="delete_class" />
                        <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Изтрий клас</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
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
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    (function(){
      var $ = window.jQuery; if (typeof $ !== 'function') return;
      var $sel = $('#student_search');
      var $list = $('#draft_list');
      var $hidden = $('#draft_students');
      var draft = [];
      try { draft = JSON.parse($hidden.val() || '[]'); } catch(e) { draft = []; }
      function sync(){ $hidden.val(JSON.stringify(draft)); }
      function render(){
        $list.empty();
        if (!draft.length) { $list.append('<div class="list-group-item text-muted">Няма ученици в списъка.</div>'); return; }
        draft.forEach(function(it,idx){
          var title = it.text ? it.text : ((it.first_name||'')+' '+(it.last_name||'')+' — '+(it.email||''));
          var row = $('<div class="list-group-item d-flex justify-content-between align-items-center"><div class="small"></div><button type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></div>');
          row.find('.small').text(title);
          row.find('button').on('click', function(){ draft.splice(idx,1); sync(); render(); });
          $list.append(row);
        });
      }
      if ($sel.length) {
        $sel.select2({
          placeholder: 'Изберете ученик...',
          allowClear: true,
          ajax: { url: 'students_search.php', delay: 250, dataType: 'json', data: function(params){ return { q:(params.term||''), class_id: <?= (int)$class_id ?> }; }, processResults: function(data){ return { results: data.results || [] }; } },
          minimumInputLength: 1,
          width: '100%'
        });
      }
      $('#addSelected').on('click', function(){
        var data = $sel.select2 ? $sel.select2('data') : [];
        if (!data || !data.length) return;
        var d = data[0];
        if (!draft.some(function(x){ return x.id == d.id; })) { draft.push({ id: parseInt(d.id,10), text: d.text }); sync(); render(); }
        $sel.val(null).trigger('change');
      });
      $('#addManual').on('click', function(){
        var email = ($('#new_email').val()||'').trim();
        var first = ($('#new_first').val()||'').trim();
        var last  = ($('#new_last').val()||'').trim();
        if (!email || !first || !last) return;
        draft.push({ email: email, first_name: first, last_name: last });
        $('#new_email,#new_first,#new_last').val('');
        sync(); render();
      });
      render();
    })();
    </script>
</footer>
</body>
</html>
