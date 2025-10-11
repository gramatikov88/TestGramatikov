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

function random_password($length = 10): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i=0; $i<$length; $i++) { $out .= $alphabet[random_int(0, strlen($alphabet)-1)]; }
    return $out;
}

$class_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
// Be robust on POST where some servers drop the query string
if ($class_id === 0 && isset($_POST['id'])) {
    $class_id = (int)$_POST['id'];
}
$editing = $class_id > 0;
$errors = [];
$saved = false;
$created_password = null;

// Load existing class if editing
$class = null;
if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = :id AND teacher_id = :tid');
    $stmt->execute([':id'=>$class_id, ':tid'=>$user['id']]);
    $class = $stmt->fetch();
    if (!$class) { header('Location: classes_create.php'); exit; }
}

// Handle class create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'save_class') {
    $name = trim((string)($_POST['name'] ?? ''));
    $grade = max(1, (int)($_POST['grade'] ?? 1));
    $section = trim((string)($_POST['section'] ?? ''));
    $school_year = (int)($_POST['school_year'] ?? date('Y'));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($name === '') $errors[] = 'Моля, въведете име на класа.';
    if ($section === '') $errors[] = 'Моля, въведете паралелка (буква).';

    if (!$errors) {
        try {
            if ($editing) {
                $stmt = $pdo->prepare('UPDATE classes SET name=:name, grade=:grade, section=:section, school_year=:sy, description=:desc WHERE id=:id AND teacher_id=:tid');
                $stmt->execute([':name'=>$name, ':grade'=>$grade, ':section'=>$section, ':sy'=>$school_year, ':desc'=>$description, ':id'=>$class_id, ':tid'=>$user['id']]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO classes (teacher_id, name, grade, section, school_year, description) VALUES (:tid,:name,:grade,:section,:sy,:desc)');
                $stmt->execute([':tid'=>$user['id'], ':name'=>$name, ':grade'=>$grade, ':section'=>$section, ':sy'=>$school_year, ':desc'=>$description]);
                $class_id = (int)$pdo->lastInsertId();
                $editing = true;
                // Reload
                $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = :id AND teacher_id = :tid');
                $stmt->execute([':id'=>$class_id, ':tid'=>$user['id']]);
                $class = $stmt->fetch();
            }
            $saved = true;
        } catch (PDOException $e) {
            if ($e->getCode()==='23000') { $errors[]='Вече имате клас със същите параметри (клас, паралелка, година).'; }
            else { $errors[] = 'Грешка при запис: '.$e->getMessage(); }
        }
    }
}

// Handle add student
if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'add_student') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Въведете валиден имейл.';
    if ($first === '') $errors[] = 'Въведете име.';
    if ($last === '') $errors[] = 'Въведете фамилия.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // Check existing user
            $stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email'=>$email]);
            $u = $stmt->fetch();
            $student_id = null;
            if ($u) {
                if ($u['role'] !== 'student') { throw new RuntimeException('Съществува потребител с този имейл, който не е ученик.'); }
                $student_id = (int)$u['id'];
            } else {
                $pwd = random_password(10);
                $created_password = $pwd; // show to teacher to предаде на ученика
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (role, email, password_hash, first_name, last_name) VALUES ("student", :email, :hash, :first, :last)');
                $stmt->execute([':email'=>$email, ':hash'=>$hash, ':first'=>$first, ':last'=>$last]);
                $student_id = (int)$pdo->lastInsertId();
            }
            // Enroll
            $stmt = $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid, :sid)');
            $stmt->execute([':cid'=>$class_id, ':sid'=>$student_id]);
            $pdo->commit();
            $saved = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Грешка при добавяне на ученик: '.$e->getMessage();
        }
    }
}
// Handle enroll existing student via dropdown
if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'enroll_student') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    if ($student_id <= 0) {
        $errors[] = 'Моля, изберете ученик от списъка.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = :id AND role = "student"');
            $stmt->execute([':id' => $student_id]);
            if (!$stmt->fetchColumn()) {
                $errors[] = 'Невалиден избор на ученик.';
            } else {
                $stmt = $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid, :sid)');
                $stmt->execute([':cid' => $class_id, ':sid' => $student_id]);
                $saved = true;
            }
        } catch (Throwable $e) {
            $errors[] = 'Грешка при добавяне на ученик: ' . $e->getMessage();
        }
    }
}

// Handle remove student
if ($editing && isset($_GET['remove_student'])) {
    $sid = (int)$_GET['remove_student'];
    $pdo->prepare('DELETE cs FROM class_students cs JOIN classes c ON c.id = cs.class_id AND c.teacher_id = :tid WHERE cs.class_id = :cid AND cs.student_id = :sid')
        ->execute([':tid'=>$user['id'], ':cid'=>$class_id, ':sid'=>$sid]);
    header('Location: classes_create.php?id='.$class_id.'#students');
    exit;
}

// Load students in class
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
    <title><?= $editing ? 'Редакция на клас' : 'Нов клас' ?> – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .scroll-area { max-height: 280px; overflow: auto; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 m-0"><?= $editing ? 'Редакция на клас' : 'Създаване на клас' ?></h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
    </div>

    <?php if ($saved): ?><div class="alert alert-success">Данните са запазени.</div><?php endif; ?>
    <?php if ($created_password): ?><div class="alert alert-info">Създаден е нов ученик. Временна парола: <strong><?= htmlspecialchars($created_password) ?></strong></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="m-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form method="post" class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Основни данни за класа</strong></div>
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
                <label class="form-label">Уч. година</label>
                <input type="number" name="school_year" class="form-control" min="2000" max="2100" value="<?= htmlspecialchars($class['school_year'] ?? date('Y')) ?>" required />
            </div>
            <div class="col-12">
                <label class="form-label">Описание</label>
                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($class['description'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end">
            <input type="hidden" name="id" value="<?= (int)$class_id ?>" />
            <input type="hidden" name="__action" value="save_class" />
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Запази</button>
        </div>
    </form>

    <?php if ($editing): ?>
    <a id="students"></a>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Добавяне на ученик (търсене)</strong></div>
                <form method="post">
                    <div class="card-body">
                        <label class="form-label">Търсене по име или имейл</label>
                        <select id="student_search" name="student_id" class="form-select" style="width:100%"></select>
                        <div class="form-text">Започнете да пишете за да търсите.</div>
                    </div>
                    <div class="card-footer bg-white d-flex justify-content-end">
                        <input type="hidden" name="id" value="<?= (int)$class_id ?>" />
                        <input type="hidden" name="__action" value="enroll_student" />
                        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-person-plus me-1"></i>Добави избран</button>
                    </div>
                </form>
            </div>
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Добавяне на ученик</strong></div>
                <form method="post">
                    <div class="card-body row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Имейл</label>
                            <input type="email" name="email" class="form-control" placeholder="email@domain.com" required />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Име</label>
                            <input type="text" name="first_name" class="form-control" required />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Фамилия</label>
                            <input type="text" name="last_name" class="form-control" required />
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex justify-content-end">
                        <input type="hidden" name="id" value="<?= (int)$class_id ?>" />
                        <input type="hidden" name="__action" value="add_student" />
                        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-person-plus me-1"></i>Добави</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Ученици в класа</strong></div>
                <div class="list-group list-group-flush scroll-area">
                    <?php if (!$students): ?><div class="list-group-item text-muted">Няма добавени ученици.</div><?php endif; ?>
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
        var $ = window.jQuery;
        if (typeof $ === 'function') {
            var $sel = $('#student_search');
            if ($sel.length) {
                $sel.select2({
                    placeholder: 'Изберете ученик...',
                    allowClear: true,
                    ajax: {
                        url: 'students_search.php',
                        delay: 250,
                        dataType: 'json',
                        data: function (params) {
                            return { q: params.term || '', class_id: <?= (int)$class_id ?> };
                        },
                        processResults: function (data) {
                            return { results: data.results || [] };
                        }
                    },
                    minimumInputLength: 1,
                    width: '100%'
                });
            }
        }
    })();
    </script>
</footer>
</body>
</html>
