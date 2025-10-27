<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' && !empty($_SESSION['pending_class_code'])) {
    $code = (string)$_SESSION['pending_class_code'];
}

$pdo = null;
$class = null;
$error = null;
$statusMessage = $_SESSION['join_class_status'] ?? null;
unset($_SESSION['join_class_status']);

try {
    $pdo = db();
    ensure_class_invite_token($pdo);
} catch (Throwable $e) {
    $error = 'We could not connect to the database right now. Please try again in a moment.';
}

if ($pdo && $code !== '') {
    $stmt = $pdo->prepare('SELECT c.*, u.first_name AS teacher_first, u.last_name AS teacher_last
                           FROM classes c
                           JOIN users u ON u.id = c.teacher_id
                           WHERE c.join_token = :token
                           LIMIT 1');
    $stmt->execute([':token' => $code]);
    $class = $stmt->fetch();
    if (!$class) {
        $error = 'This invitation is no longer valid.';
    }
} elseif ($code === '') {
    $error = 'Missing invitation code.';
}

$user = $_SESSION['user'] ?? null;
if (!empty($_SESSION['pending_class_code']) && $user) {
    unset($_SESSION['pending_class_code']);
}

if (!$user) {
    if ($code !== '') {
        $target = 'join_class.php?code=' . urlencode($code);
        $safeTarget = sanitize_redirect_path($target);
        if ($safeTarget !== '') {
            $_SESSION['after_login_redirect'] = $safeTarget;
            $_SESSION['pending_class_code'] = $code;
            header('Location: login.php?next=' . urlencode($safeTarget));
            exit;
        }
    }
    header('Location: login.php');
    exit;
}

if (!empty($_SESSION['after_login_redirect']) && sanitize_redirect_path((string)$_SESSION['after_login_redirect']) === 'join_class.php?code=' . $code) {
    unset($_SESSION['after_login_redirect']);
}

$isStudent = ($user['role'] ?? '') === 'student';
$alreadyMember = false;
$joined = false;

if ($class && $pdo && $isStudent) {
    $memberStmt = $pdo->prepare('SELECT 1 FROM class_students WHERE class_id = :cid AND student_id = :sid LIMIT 1');
    $memberStmt->execute([
        ':cid' => (int)$class['id'],
        ':sid' => (int)$user['id'],
    ]);
    $alreadyMember = (bool)$memberStmt->fetchColumn();
}

if ($class && $pdo && $isStudent && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'join_class') {
    if (!$alreadyMember) {
        try {
            $ins = $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid, :sid)');
            $ins->execute([
                ':cid' => (int)$class['id'],
                ':sid' => (int)$user['id'],
            ]);
            $joined = $ins->rowCount() > 0;
            $alreadyMember = true;
            $_SESSION['join_class_status'] = $joined ? 'joined' : 'already';
        } catch (Throwable $e) {
            $_SESSION['join_class_status'] = 'error';
        }
    } else {
        $_SESSION['join_class_status'] = 'already';
    }
    header('Location: join_class.php?code=' . urlencode($code));
    exit;
}

$teacherName = '';
$gradeLabel = '';
$sectionLabel = '';
$schoolYearLabel = '';
if ($class) {
    $teacherFirst = (string)($class['teacher_first'] ?? '');
    $teacherLast = (string)($class['teacher_last'] ?? '');
    $teacherName = trim($teacherFirst . ' ' . $teacherLast);
    $gradeLabel = isset($class['grade']) ? (string)$class['grade'] : '';
    $sectionLabel = (string)($class['section'] ?? '');
    $schoolYearLabel = isset($class['school_year']) ? (string)$class['school_year'] : '';
}

?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Join Class - TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($statusMessage === 'joined'): ?>
                <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>You have joined the class successfully.</div>
            <?php elseif ($statusMessage === 'already'): ?>
                <div class="alert alert-info"><i class="bi bi-info-circle-fill me-2"></i>You are already enrolled in this class.</div>
            <?php elseif ($statusMessage === 'error'): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>We could not add you to the class. Please try again.</div>
            <?php endif; ?>

            <?php if ($class): ?>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h1 class="h4 mb-3"><?= htmlspecialchars($class['name']) ?></h1>
                        <ul class="list-unstyled text-muted small mb-4">
                            <?php if ($teacherName !== ''): ?>
                                <li><strong>Teacher:</strong> <?= htmlspecialchars($teacherName) ?></li>
                            <?php endif; ?>
                            <li><strong>Grade / Section:</strong> <?= htmlspecialchars($gradeLabel . ($sectionLabel !== '' ? (' ' . $sectionLabel) : '')) ?></li>
                            <li><strong>School Year:</strong> <?= htmlspecialchars($schoolYearLabel) ?></li>
                            <?php if (!empty($class['description'])): ?>
                                <li><strong>Description:</strong> <?= htmlspecialchars($class['description']) ?></li>
                            <?php endif; ?>
                        </ul>

                        <?php if ($isStudent): ?>
                            <?php if ($alreadyMember): ?>
                                <div class="alert alert-info d-flex align-items-center gap-2">
                                    <i class="bi bi-people-fill"></i>
                                    <span>Вие вече сте добавени в този клас</span>
                                </div>
                            <?php else: ?>
                                <p class="mb-3">Кликнете на бутона, за да бъдете добавени в клас</p>
                                <form method="post" class="mb-3">
                                    <input type="hidden" name="__action" value="join_class" />
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Присъедини се към класа</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-warning d-flex align-items-center gap-2">
                                <i class="bi bi-person-fill-exclamation"></i>
                                <span>Само ученически акаунти могат да се присъединят към клас чрез този линк.</span>
                            </div>
                        <?php endif; ?>

                        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-speedometer2 me-1"></i>Отидете на таблото</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-link-45deg display-5 text-muted"></i>
                        <h2 class="h5 mt-3">Поканата не е налична</h2>
                        <p class="text-muted">Моля, попитайте учителя си за нов QR код или линк за клас.</p>
                        <a href="index.php" class="btn btn-primary"><i class="bi bi-house me-1"></i>Назад към началото</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<footer class="border-top py-4 mt-5">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <div class="text-muted">&copy; <?= date('Y'); ?> TestGramatikov</div>
        <div class="d-flex gap-3 small">
            <a class="text-decoration-none" href="terms.php">Условия за ползване</a>
            <a class="text-decoration-none" href="privacy.php">Декларация за поверителност</a>
            <a class="text-decoration-none" href="contact.php">Контакт</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</footer>
</body>
</html>

