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

/**
 * Генерира силна временна парола за нов ученик.
 */
function random_password(int $length = 10): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

$class_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['class_id'] ?? 0);
$editing  = $class_id > 0;

$errors = [];
$saved = false;

// flash съобщения
$created_passwords = [];
if (!empty($_SESSION['flash_saved'])) {
    $saved = true;
    unset($_SESSION['flash_saved']);
}
if (!empty($_SESSION['flash_created_passwords']) && is_array($_SESSION['flash_created_passwords'])) {
    $created_passwords = $_SESSION['flash_created_passwords'];
    unset($_SESSION['flash_created_passwords']);
}

// Подготви структура за „опашка“ от ученици при нов клас
if (!isset($_SESSION['pending_students']) || !is_array($_SESSION['pending_students'])) {
    $_SESSION['pending_students'] = [];
}

// Зареди клас при редакция
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

/**
 * Помощна функция: създава/намира ученик и го добавя към даден class_id.
 * Връща масив с:
 *  - 'created_password' => ?string (ако е създаден нов акаунт)
 */
function ensure_student_in_class(PDO $pdo, int $class_id, string $email, string $first, string $last): array {
    $email = mb_strtolower(trim($email));
    $first = trim($first);
    $last  = trim($last);

    $created_password = null;

    $pdo->beginTransaction();

    try {
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
            $stmt = $pdo->prepare('INSERT INTO users (role, email, password_hash, first_name, last_name) 
                                   VALUES ("student", :email, :hash, :first, :last)');
            $stmt->execute([
                ':email' => $email,
                ':hash'  => $hash,
                ':first' => $first,
                ':last'  => $last,
            ]);
            $student_id = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid, :sid)');
        $stmt->execute([':cid' => $class_id, ':sid' => $student_id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    return ['created_password' => $created_password];
}

/**
 * Валидация на вход за ученик (обща за двете форми).
 */
function validate_student_payload(array $post, array &$errors): ?array {
    $email = mb_strtolower(trim((string)($post['email'] ?? '')));
    $first = trim((string)($post['first_name'] ?? ''));
    $last  = trim((string)($post['last_name'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Въведете валиден имейл.'; }
    if ($first === '') { $errors[] = 'Въведете име.'; }
    if ($last === '')  { $errors[] = 'Въведете фамилия.'; }

    if ($errors) return null;
    return ['email' => $email, 'first_name' => $first, 'last_name' => $last];
}

// === ДЕЙСТВИЯ ===

// 1) Запис/създаване на клас
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'save_class') {
    $name        = trim((string)($_POST['name'] ?? ''));
    $grade       = max(1, (int)($_POST['grade'] ?? 1));
    $section     = trim((string)($_POST['section'] ?? ''));
    $school_year = (int)($_POST['school_year'] ?? date('Y'));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($name === '')    { $errors[] = 'Моля, въведете име на класа.'; }
    if ($section === '') { $errors[] = 'Моля, въведете паралелка (буква).'; }

    if (!$errors) {
        try {
            if ($editing) {
                $stmt = $pdo->prepare('UPDATE classes 
                    SET name=:name, grade=:grade, section=:section, school_year=:sy, description=:desc 
                    WHERE id=:id AND teacher_id=:tid');
                $stmt->execute([
                    ':name' => $name,
                    ':grade'=> $grade,
                    ':section'=>$section,
                    ':sy'   => $school_year,
                    ':desc' => $description,
                    ':id'   => $class_id,
                    ':tid'  => (int)$user['id'],
                ]);
            } else {
                // Създай клас
                $stmt = $pdo->prepare('INSERT INTO classes (teacher_id, name, grade, section, school_year, description) 
                                       VALUES (:tid,:name,:grade,:section,:sy,:desc)');
                $stmt->execute([
                    ':tid'  => (int)$user['id'],
                    ':name' => $name,
                    ':grade'=> $grade,
                    ':section'=>$section,
                    ':sy'   => $school_year,
                    ':desc' => $description,
                ]);
                $class_id = (int)$pdo->lastInsertId();
                $editing = true; // вече имаме клас

                // Ако има чакащи ученици (опашка) — добави ги
                $pwds = [];
                if (!empty($_SESSION['pending_students'])) {
                    foreach ($_SESSION['pending_students'] as $queued) {
                        try {
                            $res = ensure_student_in_class(
                                $pdo,
                                $class_id,
                                $queued['email'],
                                $queued['first_name'],
                                $queued['last_name']
                            );
                            if (!empty($res['created_password'])) {
                                $pwds[] = [
                                    'email'    => $queued['email'],
                                    'password' => $res['created_password']
                                ];
                            }
                        } catch (Throwable $e) {
                            // Не прекъсваме целия процес, а само отбелязваме грешка
                            $errors[] = 'Грешка при добавяне на ученик ' . htmlspecialchars($queued['email']) . ': ' . $e->getMessage();
                        }
                    }
                    // Изчисти опашката
                    $_SESSION['pending_students'] = [];
                }
                if ($pwds) {
                    $_SESSION['flash_created_passwords'] = $pwds;
                }
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

// 2) Добавяне на ученик към СЪЩЕСТВУВАЩ клас (старият ти работещ поток – запазен)
if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'add_student') {
    $payload = validate_student_payload($_POST, $errors);
    if ($payload && !$errors) {
        try {
            $res = ensure_student_in_class($pdo, $class_id, $payload['email'], $payload['first_name'], $payload['last_name']);
            $pwds = [];
            if (!empty($res['created_password'])) {
                $pwds[] = ['email' => $payload['email'], 'password' => $res['created_password']];
            }
            $_SESSION['flash_saved'] = 1;
            if ($pwds) { $_SESSION['flash_created_passwords'] = $pwds; }
            header('Location: classes_create.php?id=' . $class_id . '#students');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Грешка при добавяне на ученик: ' . $e->getMessage();
        }
    }
}

// 3) „Опашка“: добавяне на ученик ДОКАТО СЪЗДАВАМЕ НОВ клас (нямаме още $class_id)
if (!$editing && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'queue_student') {
    $payload = validate_student_payload($_POST, $errors);
    if ($payload && !$errors) {
        $_SESSION['pending_students'][] = $payload;
        // Мек „флаш“: да се покаже успешна нотификация
        $_SESSION['flash_saved'] = 1;
        header('Location: classes_create.php');
        exit;
    }
}

// 4) Премахване на ученик от съществуващ клас
if ($editing && isset($_GET['remove_student'])) {
    $sid = (int)$_GET['remove_student'];
    $pdo->prepare('DELETE cs 
        FROM class_students cs 
        JOIN classes c ON c.id = cs.class_id AND c.teacher_id = :tid 
        WHERE cs.class_id = :cid AND cs.student_id = :sid')
        ->execute([':tid' => (int)$user['id'], ':cid' => $class_id, ':sid' => $sid]);
    header('Location: classes_create.php?id=' . $class_id . '#students');
    exit;
}

// 5) Премахване на ученик от „опашката“ (нов клас)
if (!$editing && isset($_GET['remove_queued'])) {
    $idx = (int)$_GET['remove_queued'];
    if (isset($_SESSION['pending_students'][$idx])) {
        unset($_SESSION['pending_students'][$idx]);
        $_SESSION['pending_students'] = array_values($_SESSION['pending_students']); // преподреди индексите
    }
    header('Location: classes_create.php#students');
    exit;
}

// Зареди ученици за визуализация
$students = [];
if ($editing) {
    $stmt = $pdo->prepare('SELECT u.id, u.first_name, u.last_name, u.email
                           FROM class_students cs 
                           JOIN users u ON u.id = cs.student_id
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

    <?php if ($saved): ?>
        <div class="alert alert-success">Данните са запазени.</div>
    <?php endif; ?>

    <?php if (!empty($created_passwords)): ?>
        <div class="alert alert-info">
            <div class="fw-semibold mb-1">Създадени са нови ученици. Временни пароли:</div>
            <ul class="mb-0">
                <?php foreach ($created_passwords as $cp): ?>
                    <li><?= htmlspecialchars($cp['email']) ?> — <strong><?= htmlspecialchars($cp['password']) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="m-0 ps-3">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Форма: основни данни за класа -->
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
            <button class="btn btn-primary" type="submit" name="__action" value="save_class">
                <i class="bi bi-check2-circle me-1"></i>Запази
            </button>
        </div>
    </form>

    <!-- Блок „Ученици“: работи както при редакция, така и при нов клас (опашка) -->
    <a id="students"></a>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong><?= $editing ? 'Добавяне на ученик' : 'Добавяне на ученик (ще се запази при създаване на класа)' ?></strong>
                </div>
                <form method="post" action="classes_create.php<?= $editing ? ('?id='.(int)$class_id) : '' ?>#students">
                    <div class="card-body row g-3">
                        <?php if ($editing): ?>
                            <input type="hidden" name="class_id" value="<?= (int)$class_id ?>" />
                        <?php endif; ?>
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
                        <?php if ($editing): ?>
                            <button class="btn btn-outline-primary" type="submit" name="__action" value="add_student">
                                <i class="bi bi-person-plus me-1"></i>Добави
                            </button>
                        <?php else: ?>
                            <button class="btn btn-outline-primary" type="submit" name="__action" value="queue_student">
                                <i class="bi bi-person-plus me-1"></i>Добави в списъка
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Ученици в класа</strong></div>

                <?php if ($editing): ?>
                    <div class="list-group list-group-flush scroll-area">
                        <?php if (!$students): ?>
                            <div class="list-group-item text-muted">Няма добавени ученици.</div>
                        <?php endif; ?>
                        <?php foreach ($students as $s): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($s['email']) ?></div>
                                </div>
                                <a class="btn btn-sm btn-outline-danger"
                                   href="classes_create.php?id=<?= (int)$class_id ?>&remove_student=<?= (int)$s['id'] ?>"
                                   onclick="return confirm('Премахване на ученика от класа?');">
                                   <i class="bi bi-x"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- Визуализация на „опашката“ при нов клас -->
                    <div class="list-group list-group-flush scroll-area">
                        <?php if (empty($_SESSION['pending_students'])): ?>
                            <div class="list-group-item text-muted">Все още няма добавени ученици. Добави ги отляво и натисни „Запази“ за класа.</div>
                        <?php else: ?>
                            <?php foreach ($_SESSION['pending_students'] as $idx => $ps): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold">
                                            <?= htmlspecialchars(($ps['first_name'] ?? '') . ' ' . ($ps['last_name'] ?? '')) ?>
                                        </div>
                                        <div class="text-muted small"><?= htmlspecialchars($ps['email'] ?? '') ?></div>
                                    </div>
                                    <a class="btn btn-sm btn-outline-danger"
                                       href="classes_create.php?remove_queued=<?= (int)$idx ?>#students"
                                       onclick="return confirm('Премахване на ученика от списъка?');">
                                        <i class="bi bi-x"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
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