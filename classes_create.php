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

function random_password(int $length = 10): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

$class_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['class_id'] ?? 0);
$editing = $class_id > 0;
$errors = [];
$saved = false;
$created_password = null;

if (!empty($_SESSION['flash_saved'])) {
    $saved = true;
    unset($_SESSION['flash_saved']);
}
if (!empty($_SESSION['flash_created_password'])) {
    $created_password = $_SESSION['flash_created_password'];
    unset($_SESSION['flash_created_password']);
}

$class = null;
if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = :id AND teacher_id = :tid');
    $stmt->execute([':id' => $class_id, ':tid' => (int)$user['id']]);
    $class = $stmt->fetch();
    if (!$class) {
        header('Location: dashboard.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'save_class') {
    $name = trim((string)($_POST['name'] ?? ''));
    $grade = max(1, (int)($_POST['grade'] ?? 1));
    $section = trim((string)($_POST['section'] ?? ''));
    $school_year = (int)($_POST['school_year'] ?? date('Y'));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($name === '') { $errors[] = 'Моля, въведете име на класа.'; }
    if ($section === '') { $errors[] = 'Моля, въведете паралелка (буква).'; }

    if (!$errors) {
        try {
            if ($editing) {
                $stmt = $pdo->prepare('UPDATE classes SET name=:name, grade=:grade, section=:section, school_year=:sy, description=:desc WHERE id=:id AND teacher_id=:tid');
                $stmt->execute([
                    ':name' => $name,
                    ':grade' => $grade,
                    ':section' => $section,
                    ':sy' => $school_year,
                    ':desc' => $description,
                    ':id' => $class_id,
                    ':tid' => (int)$user['id'],
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO classes (teacher_id, name, grade, section, school_year, description) VALUES (:tid,:name,:grade,:section,:sy,:desc)');
                $stmt->execute([
                    ':tid' => (int)$user['id'],
                    ':name' => $name,
                    ':grade' => $grade,
                    ':section' => $section,
                    ':sy' => $school_year,
                    ':desc' => $description,
                ]);
                $class_id = (int)$pdo->lastInsertId();
            }
            $_SESSION['flash_saved'] = 1;
            header('Location: classes_create.php?id=' . $class_id);
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Вече имате клас със същите параметри (клас, паралелка, година).';
            } else {
                $errors[] = 'Грешка при запис: ' . $e->getMessage();
            }
        }
    }
}

if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'add_student') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Въведете валиден имейл.'; }
    if ($first === '') { $errors[] = 'Въведете име.'; }
    if ($last === '') { $errors[] = 'Въведете фамилия.'; }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $existing = $stmt->fetch();

            if ($existing) {
                if ($existing['role'] !== 'student') {
                    throw new RuntimeException('Съществува потребител с този имейл, който не е ученик.');
                }
                $student_id = (int)$existing['id'];
            } else {
                $pwd = random_password();
                $created_password = $pwd;
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (role, email, password_hash, first_name, last_name) VALUES ("student", :email, :hash, :first, :last)');
                $stmt->execute([
                    ':email' => $email,
                    ':hash' => $hash,
                    ':first' => $first,
                    ':last' => $last,
                ]);
                $student_id = (int)$pdo->lastInsertId();
            }

            $stmt = $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid, :sid)');
            $stmt->execute([':cid' => $class_id, ':sid' => $student_id]);
            $pdo->commit();

            $_SESSION['flash_saved'] = 1;
            if ($created_password) { $_SESSION['flash_created_password'] = $created_password; }
            header('Location: classes_create.php?id=' . $class_id . '#students');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Грешка при добавяне на ученик: ' . $e->getMessage();
        }
    }
}

if ($editing && isset($_GET['remove_student'])) {
    $sid = (int)$_GET['remove_student'];
    $pdo->prepare('DELETE cs FROM class_students cs JOIN classes c ON c.id = cs.class_id AND c.teacher_id = :tid WHERE cs.class_id = :cid AND cs.student_id = :sid')
        ->execute([':tid' => (int)$user['id'], ':cid' => $class_id, ':sid' => $sid]);
    header('Location: classes_create.php?id=' . $class_id . '#students');
    exit;
}

$students = [];
if ($editing) {
    $stmt = $pdo->prepare('SELECT u.id, u.first_name, u.last_name, u.email
                            FROM class_students cs JOIN users u ON u.id = cs.student_id
                            WHERE cs.class_id = :cid
                            ORDER BY u.first_name, u.last_name');
    $stmt->execute([':cid' => $class_id]);
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
    <style>.scroll-area{max-height:300px;overflow:auto}</style>
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

    <form method="post" action="classes_create.php<?= $editing ? '?id='.(int)$class_id : '' ?>" class="card shadow-sm mb-4">
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
            <input type="hidden" name="class_id" value="<?= (int)$class_id ?>" />
            <button class="btn btn-primary" type="submit" name="__action" value="save_class"><i class="bi bi-check2-circle me-1"></i>Запази</button>
        </div>
    </form>

    <?php if ($editing): ?>
    <a id="students"></a>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Добавяне на ученик</strong></div>
                <form method="post" action="classes_create.php?id=<?= (int)$class_id ?>#students">
                    <div class="card-body row g-3">
                        <input type="hidden" name="class_id" value="<?= (int)$class_id ?>" />
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
                        <button class="btn btn-outline-primary" type="submit" name="__action" value="add_student"><i class="bi bi-person-plus me-1"></i>Добави</button>
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
                                <div class="fw-semibold"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></div>
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
</footer>
</body>
</html>
