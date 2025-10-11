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
$created_accounts = [];

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
    $draft_students_json = (string)($_POST['draft_students'] ?? '');
    $draft_students = [];
    if ($draft_students_json !== '') {
        try { $draft_students = json_decode($draft_students_json, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { $errors[] = 'ÃÂÃÂµÃÂ²ÃÂ°ÃÂ»ÃÂ¸ÃÂ´ÃÂ½ÃÂ¸ ÃÂ´ÃÂ°ÃÂ½ÃÂ½ÃÂ¸ ÃÂ·ÃÂ° Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸Ã‘â€ ÃÂ¸Ã‘â€šÃÂµ.'; $draft_students = []; }
    }

    if ($name === '') $errors[] = 'ÃÅ“ÃÂ¾ÃÂ»Ã‘Â, ÃÂ²Ã‘Å ÃÂ²ÃÂµÃÂ´ÃÂµÃ‘â€šÃÂµ ÃÂ¸ÃÂ¼ÃÂµ ÃÂ½ÃÂ° ÃÂºÃÂ»ÃÂ°Ã‘ÂÃÂ°.';
    if ($section === '') $errors[] = 'ÃÅ“ÃÂ¾ÃÂ»Ã‘Â, ÃÂ²Ã‘Å ÃÂ²ÃÂµÃÂ´ÃÂµÃ‘â€šÃÂµ ÃÂ¿ÃÂ°Ã‘â‚¬ÃÂ°ÃÂ»ÃÂµÃÂ»ÃÂºÃÂ° (ÃÂ±Ã‘Æ’ÃÂºÃÂ²ÃÂ°).';

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
            // Ãâ€ÃÂ¾ÃÂ±ÃÂ°ÃÂ²Ã‘ÂÃÂ½ÃÂµ ÃÂ½ÃÂ° Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸Ã‘â€ ÃÂ¸Ã‘â€šÃÂµ ÃÂ¾Ã‘â€š Ã‘â€¡ÃÂµÃ‘â‚¬ÃÂ½ÃÂ¾ÃÂ²ÃÂ°Ã‘â€šÃÂ° (ÃÂ°ÃÂºÃÂ¾ ÃÂ¸ÃÂ¼ÃÂ° ÃÂ¿ÃÂ¾ÃÂ´ÃÂ°ÃÂ´ÃÂµÃÂ½ÃÂ¸)
            if (!empty($draft_students) && $class_id > 0) {
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
            $saved = true;
            header('Location: classes_create.php?id='.$class_id.'#students');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode()==='23000') { $errors[]='Ãâ€™ÃÂµÃ‘â€¡ÃÂµ ÃÂ¸ÃÂ¼ÃÂ°Ã‘â€šÃÂµ ÃÂºÃÂ»ÃÂ°Ã‘Â Ã‘ÂÃ‘Å Ã‘Â Ã‘ÂÃ‘Å Ã‘â€°ÃÂ¸Ã‘â€šÃÂµ ÃÂ¿ÃÂ°Ã‘â‚¬ÃÂ°ÃÂ¼ÃÂµÃ‘â€šÃ‘â‚¬ÃÂ¸ (ÃÂºÃÂ»ÃÂ°Ã‘Â, ÃÂ¿ÃÂ°Ã‘â‚¬ÃÂ°ÃÂ»ÃÂµÃÂ»ÃÂºÃÂ°, ÃÂ³ÃÂ¾ÃÂ´ÃÂ¸ÃÂ½ÃÂ°).'; }
            else { $errors[] = 'Ãâ€œÃ‘â‚¬ÃÂµÃ‘Ë†ÃÂºÃÂ° ÃÂ¿Ã‘â‚¬ÃÂ¸ ÃÂ·ÃÂ°ÃÂ¿ÃÂ¸Ã‘Â: '.$e->getMessage(); }
        }
    }
}

// Handle add student
if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'add_student') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ãâ€™Ã‘Å ÃÂ²ÃÂµÃÂ´ÃÂµÃ‘â€šÃÂµ ÃÂ²ÃÂ°ÃÂ»ÃÂ¸ÃÂ´ÃÂµÃÂ½ ÃÂ¸ÃÂ¼ÃÂµÃÂ¹ÃÂ».';
    if ($first === '') $errors[] = 'Ãâ€™Ã‘Å ÃÂ²ÃÂµÃÂ´ÃÂµÃ‘â€šÃÂµ ÃÂ¸ÃÂ¼ÃÂµ.';
    if ($last === '') $errors[] = 'Ãâ€™Ã‘Å ÃÂ²ÃÂµÃÂ´ÃÂµÃ‘â€šÃÂµ Ã‘â€žÃÂ°ÃÂ¼ÃÂ¸ÃÂ»ÃÂ¸Ã‘Â.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // Check existing user
            $stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email'=>$email]);
            $u = $stmt->fetch();
            $student_id = null;
            if ($u) {
                if ($u['role'] !== 'student') { throw new RuntimeException('ÃÂ¡Ã‘Å Ã‘â€°ÃÂµÃ‘ÂÃ‘â€šÃÂ²Ã‘Æ’ÃÂ²ÃÂ° ÃÂ¿ÃÂ¾Ã‘â€šÃ‘â‚¬ÃÂµÃÂ±ÃÂ¸Ã‘â€šÃÂµÃÂ» Ã‘Â Ã‘â€šÃÂ¾ÃÂ·ÃÂ¸ ÃÂ¸ÃÂ¼ÃÂµÃÂ¹ÃÂ», ÃÂºÃÂ¾ÃÂ¹Ã‘â€šÃÂ¾ ÃÂ½ÃÂµ ÃÂµ Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂº.'); }
                $student_id = (int)$u['id'];
            } else {
                $pwd = random_password(10);
                $created_password = $pwd; // show to teacher to ÃÂ¿Ã‘â‚¬ÃÂµÃÂ´ÃÂ°ÃÂ´ÃÂµ ÃÂ½ÃÂ° Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂºÃÂ°
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
            header('Location: classes_create.php?id=' . (int)$class_id . '#students');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Ãâ€œÃ‘â‚¬ÃÂµÃ‘Ë†ÃÂºÃÂ° ÃÂ¿Ã‘â‚¬ÃÂ¸ ÃÂ´ÃÂ¾ÃÂ±ÃÂ°ÃÂ²Ã‘ÂÃÂ½ÃÂµ ÃÂ½ÃÂ° Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂº: '.$e->getMessage();
        }
    }
}
// Handle enroll existing student via dropdown
if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'enroll_student') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    if ($student_id <= 0) {
        $errors[] = 'ÃÅ“ÃÂ¾ÃÂ»Ã‘Â, ÃÂ¸ÃÂ·ÃÂ±ÃÂµÃ‘â‚¬ÃÂµÃ‘â€šÃÂµ Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂº ÃÂ¾Ã‘â€š Ã‘ÂÃÂ¿ÃÂ¸Ã‘ÂÃ‘Å ÃÂºÃÂ°.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = :id AND role = "student"');
            $stmt->execute([':id' => $student_id]);
            if (!$stmt->fetchColumn()) {
                $errors[] = 'ÃÂÃÂµÃÂ²ÃÂ°ÃÂ»ÃÂ¸ÃÂ´ÃÂµÃÂ½ ÃÂ¸ÃÂ·ÃÂ±ÃÂ¾Ã‘â‚¬ ÃÂ½ÃÂ° Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂº.';
            } else {
                $stmt = $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid, :sid)');
                $stmt->execute([':cid' => $class_id, ':sid' => $student_id]);
                $saved = true;
                header('Location: classes_create.php?id=' . (int)$class_id . '#students');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Ãâ€œÃ‘â‚¬ÃÂµÃ‘Ë†ÃÂºÃÂ° ÃÂ¿Ã‘â‚¬ÃÂ¸ ÃÂ´ÃÂ¾ÃÂ±ÃÂ°ÃÂ²Ã‘ÂÃÂ½ÃÂµ ÃÂ½ÃÂ° Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂº: ' . $e->getMessage();
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

// Handle delete class
if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'delete_class') {
    try {
        $pdo->prepare('DELETE FROM classes WHERE id = :id AND teacher_id = :tid')->execute([':id'=>$class_id, ':tid'=>$user['id']]);
        header('Location: dashboard.php');
        exit;
    } catch (Throwable $e) {
        $errors[] = 'ÃÂÃÂµÃ‘Æ’Ã‘ÂÃÂ¿ÃÂµÃ‘Ë†ÃÂ½ÃÂ¾ ÃÂ¸ÃÂ·Ã‘â€šÃ‘â‚¬ÃÂ¸ÃÂ²ÃÂ°ÃÂ½ÃÂµ ÃÂ½ÃÂ° ÃÂºÃÂ»ÃÂ°Ã‘ÂÃÂ°.';
    }
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
    <title><?=  ? 'Ð ÐµÐ´Ð°ÐºÑ†Ð¸Ñ Ð½Ð° ÐºÐ»Ð°Ñ' : 'ÐÐ¾Ð² ÐºÐ»Ð°Ñ' ?> â€” TestGramatikov</title>
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
        <h1 class="h4 m-0"><?= $editing ? 'ÃÂ ÃÂµÃÂ´ÃÂ°ÃÂºÃ‘â€ ÃÂ¸Ã‘Â ÃÂ½ÃÂ° ÃÂºÃÂ»ÃÂ°Ã‘Â' : 'ÃÂ¡Ã‘Å ÃÂ·ÃÂ´ÃÂ°ÃÂ²ÃÂ°ÃÂ½ÃÂµ ÃÂ½ÃÂ° ÃÂºÃÂ»ÃÂ°Ã‘Â' ?></h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> ÃÂÃÂ°ÃÂ·ÃÂ°ÃÂ´</a>
    </div>

    <?php if ($editing): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Записани ученици</strong></div>       <div class="card-body">
            <form method="post" onsubmit="return confirm('Ð¡Ð¸Ð³ÑƒÑ€Ð½Ð¸ Ð»Ð¸ ÑÑ‚Ðµ, Ñ‡Ðµ Ð¸ÑÐºÐ°Ñ‚Ðµ Ð´Ð° Ð¸Ð·Ñ‚Ñ€Ð¸ÐµÑ‚Ðµ Ñ‚Ð¾Ð·Ð¸ ÐºÐ»Ð°Ñ?');">
                <input type="hidden" name="id" value="<?= (int)$class_id ?>" />
                <input type="hidden" name="__action" value="delete_class" />
                <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Ð˜Ð·Ñ‚Ñ€Ð¸Ð¹ ÐºÐ»Ð°Ñ</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($saved): ?><div class="alert alert-success">Ãâ€ÃÂ°ÃÂ½ÃÂ½ÃÂ¸Ã‘â€šÃÂµ Ã‘ÂÃÂ° ÃÂ·ÃÂ°ÃÂ¿ÃÂ°ÃÂ·ÃÂµÃÂ½ÃÂ¸.</div><?php endif; ?>
    <?php if ($created_password): ?><div class="alert alert-info">ÃÂ¡Ã‘Å ÃÂ·ÃÂ´ÃÂ°ÃÂ´ÃÂµÃÂ½ ÃÂµ ÃÂ½ÃÂ¾ÃÂ² Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂº. Ãâ€™Ã‘â‚¬ÃÂµÃÂ¼ÃÂµÃÂ½ÃÂ½ÃÂ° ÃÂ¿ÃÂ°Ã‘â‚¬ÃÂ¾ÃÂ»ÃÂ°: <strong><?= htmlspecialchars($created_password) ?></strong></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="m-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form method="post" class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>ÃÅ¾Ã‘ÂÃÂ½ÃÂ¾ÃÂ²ÃÂ½ÃÂ¸ ÃÂ´ÃÂ°ÃÂ½ÃÂ½ÃÂ¸ ÃÂ·ÃÂ° ÃÂºÃÂ»ÃÂ°Ã‘ÂÃÂ°</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">ÃËœÃÂ¼ÃÂµ</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($class['name'] ?? '') ?>" required />
            </div>
            <div class="col-md-2">
                <label class="form-label">ÃÅ¡ÃÂ»ÃÂ°Ã‘Â</label>
                <input type="number" name="grade" class="form-control" min="1" max="12" value="<?= htmlspecialchars($class['grade'] ?? '') ?>" required />
            </div>
            <div class="col-md-2">
                <label class="form-label">ÃÅ¸ÃÂ°Ã‘â‚¬ÃÂ°ÃÂ»ÃÂµÃÂ»ÃÂºÃÂ°</label>
                <input type="text" name="section" class="form-control" maxlength="5" value="<?= htmlspecialchars($class['section'] ?? '') ?>" required />
            </div>
            <div class="col-md-2">
                <label class="form-label">ÃÂ£Ã‘â€¡. ÃÂ³ÃÂ¾ÃÂ´ÃÂ¸ÃÂ½ÃÂ°</label>
                <input type="number" name="school_year" class="form-control" min="2000" max="2100" value="<?= htmlspecialchars($class['school_year'] ?? date('Y')) ?>" required />
            </div>
            <div class="col-12">
                <label class="form-label">ÃÅ¾ÃÂ¿ÃÂ¸Ã‘ÂÃÂ°ÃÂ½ÃÂ¸ÃÂµ</label>
                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($class['description'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end">
            <input type="hidden" name="id" value="<?= (int)$class_id ?>" />
            <input type="hidden" name="draft_students" id="draft_students" value="<?= htmlspecialchars($_POST['draft_students'] ?? '[]') ?>" />
            <input type="hidden" name="__action" value="save_class" />
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Ð—Ð°Ð¿Ð°Ð·Ð¸</button>
        </div>
    </form>

    <!-- Ð”Ð¾Ð±Ð°Ð²ÑÐ½Ðµ Ð½Ð° ÑƒÑ‡ÐµÐ½Ð¸Ñ†Ð¸ (Ñ‡ÐµÑ€Ð½Ð¾Ð²Ð° Ð¿Ð¾ Ð²Ñ€ÐµÐ¼Ðµ Ð½Ð° ÑÑŠÐ·Ð´Ð°Ð²Ð°Ð½Ðµ/Ñ€ÐµÐ´Ð°ÐºÑ†Ð¸Ñ). Ð—Ð°Ð¿Ð¸ÑÑŠÑ‚ ÑÑ‚Ð°Ð²Ð° Ñ Ð±ÑƒÑ‚Ð¾Ð½Ð° "Ð—Ð°Ð¿Ð°Ð·Ð¸" Ð³Ð¾Ñ€Ðµ. -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Ð£Ñ‡ÐµÐ½Ð¸Ñ†Ð¸ ÐºÑŠÐ¼ ÐºÐ»Ð°ÑÐ°</strong> <span class="badge bg-light text-dark ms-2">Ð´Ð¾Ð±Ð°Ð²ÑÐ½ÐµÑ‚Ð¾ ÑÑ‚Ð°Ð²Ð° Ð¿Ñ€Ð¸ â€žÐ—Ð°Ð¿Ð°Ð·Ð¸â€œ</span></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Ð¢ÑŠÑ€ÑÐµÐ½Ðµ Ð½Ð° ÑƒÑ‡ÐµÐ½Ð¸Ðº</label>
                    <select id="student_search" class="form-select" style="width:100%"></select>
                    <button class="btn btn-outline-primary mt-2" type="button" id="addSelected"><i class="bi bi-person-plus"></i> Ð”Ð¾Ð±Ð°Ð²Ð¸ Ð¸Ð·Ð±Ñ€Ð°Ð½Ð¸Ñ</button>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Ð¡ÑŠÐ·Ð´Ð°Ð²Ð°Ð½Ðµ Ð½Ð° Ð½Ð¾Ð² ÑƒÑ‡ÐµÐ½Ð¸Ðº</label>
                    <div class="row g-2">
                        <div class="col-12 col-md-6"><input type="email" id="new_email" class="form-control" placeholder="email@domain.com" /></div>
                        <div class="col-6 col-md-3"><input type="text" id="new_first" class="form-control" placeholder="Ð˜Ð¼Ðµ" /></div>
                        <div class="col-6 col-md-3"><input type="text" id="new_last" class="form-control" placeholder="Ð¤Ð°Ð¼Ð¸Ð»Ð¸Ñ" /></div>
                    </div>
                    <button class="btn btn-outline-secondary mt-2" type="button" id="addManual"><i class="bi bi-plus-lg"></i> Ð”Ð¾Ð±Ð°Ð²Ð¸ Ð² ÑÐ¿Ð¸ÑÑŠÐºÐ°</button>
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
        <div class="col-lg-6"><!-- Ð”Ð¾Ð±Ð°Ð²ÑÐ½ÐµÑ‚Ð¾ Ð½Ð° ÑƒÑ‡ÐµÐ½Ð¸Ñ†Ð¸ Ðµ Ð¿Ñ€ÐµÐ¼ÐµÑÑ‚ÐµÐ½Ð¾ Ð³Ð¾Ñ€Ðµ. --></div>
                    <div class="card-footer bg-white d-flex justify-content-end">
                        <input type="hidden" name="id" value="<?= (int)$class_id ?>" />
                        <input type="hidden" name="__action" value="add_student" />
                        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-person-plus me-1"></i>Ãâ€ÃÂ¾ÃÂ±ÃÂ°ÃÂ²ÃÂ¸</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>ÃÂ£Ã‘â€¡ÃÂµÃÂ½ÃÂ¸Ã‘â€ ÃÂ¸ ÃÂ² ÃÂºÃÂ»ÃÂ°Ã‘ÂÃÂ°</strong></div>
                <div class="list-group list-group-flush scroll-area">
                    <?php if (!$students): ?><div class="list-group-item text-muted">ÃÂÃ‘ÂÃÂ¼ÃÂ° ÃÂ´ÃÂ¾ÃÂ±ÃÂ°ÃÂ²ÃÂµÃÂ½ÃÂ¸ Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸Ã‘â€ ÃÂ¸.</div><?php endif; ?>
                    <?php foreach ($students as $s): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($s['email']) ?></div>
                            </div>
                            <a class="btn btn-sm btn-outline-danger" href="classes_create.php?id=<?= (int)$class_id ?>&remove_student=<?= (int)$s['id'] ?>" onclick="return confirm('ÃÅ¸Ã‘â‚¬ÃÂµÃÂ¼ÃÂ°Ã‘â€¦ÃÂ²ÃÂ°ÃÂ½ÃÂµ ÃÂ½ÃÂ° Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂºÃÂ° ÃÂ¾Ã‘â€š ÃÂºÃÂ»ÃÂ°Ã‘ÂÃÂ°?');"><i class="bi bi-x"></i></a>
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
        <div class="text-muted">Ã‚Â© <?= date('Y'); ?> TestGramatikov</div>
        <div class="d-flex gap-3 small">
            <a class="text-decoration-none" href="terms.php">ÃÂ£Ã‘ÂÃÂ»ÃÂ¾ÃÂ²ÃÂ¸Ã‘Â</a>
            <a class="text-decoration-none" href="privacy.php">ÃÅ¸ÃÂ¾ÃÂ²ÃÂµÃ‘â‚¬ÃÂ¸Ã‘â€šÃÂµÃÂ»ÃÂ½ÃÂ¾Ã‘ÂÃ‘â€š</a>
            <a class="text-decoration-none" href="contact.php">ÃÅ¡ÃÂ¾ÃÂ½Ã‘â€šÃÂ°ÃÂºÃ‘â€š</a>
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
                    placeholder: 'ÃËœÃÂ·ÃÂ±ÃÂµÃ‘â‚¬ÃÂµÃ‘â€šÃÂµ Ã‘Æ’Ã‘â€¡ÃÂµÃÂ½ÃÂ¸ÃÂº...',
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
    <script>
    (function(){
      var $ = window.jQuery; if (typeof $ !== 'function') return;
      var $sel = $('#student_search');
      var $list = $('#draft_list');
      var $hidden = $('#draft_students');
      if (!$hidden.length || !$list.length) return;
      var draft = [];
      try { draft = JSON.parse($hidden.val() || '[]'); } catch(e) { draft = []; }
      function sync(){ $hidden.val(JSON.stringify(draft)); }
      function render(){
        $list.empty();
        if (!draft.length) { $list.append('<div class="list-group-item text-muted">ÐÑÐ¼Ð° ÑƒÑ‡ÐµÐ½Ð¸Ñ†Ð¸ Ð² ÑÐ¿Ð¸ÑÑŠÐºÐ°.</div>'); return; }
        draft.forEach(function(it,idx){
          var title = it.text ? it.text : ((it.first_name||'')+' '+(it.last_name||'')+' - '+(it.email||''));
          var row = $('<div class="list-group-item d-flex justify-content-between align-items-center"><div class="small"></div><button type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></div>');
          row.find('.small').text(title);
          row.find('button').on('click', function(){ draft.splice(idx,1); sync(); render(); });
          $list.append(row);
        });
      }
      $('#addSelected').on('click', function(){
        var data = $sel.select2 ? $sel.select2('data') : [];
        if (!data || !data.length) return;
        var d = data[0];
        if (!draft.some(function(x){ return x.id == d.id; })) {
          draft.push({ id: parseInt(d.id,10), text: d.text });
          sync(); render();
        }
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



