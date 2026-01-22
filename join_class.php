<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$rawCode = trim((string) ($_GET['code'] ?? ''));
if ($rawCode === '' && !empty($_SESSION['pending_class_code'])) {
    $rawCode = (string) $_SESSION['pending_class_code'];
}
$code = $rawCode;
if ($rawCode !== '' && preg_match('/^[A-Za-z0-9]{6}$/', $rawCode)) {
    $code = strtoupper($rawCode);
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
        $error = 'This invitation is no longer valid or the code is incorrect.';
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

if (!empty($_SESSION['after_login_redirect']) && sanitize_redirect_path((string) $_SESSION['after_login_redirect']) === 'join_class.php?code=' . $code) {
    unset($_SESSION['after_login_redirect']);
}

$isStudent = ($user['role'] ?? '') === 'student';
$alreadyMember = false;
$joined = false;

if ($class && $pdo && $isStudent) {
    $memberStmt = $pdo->prepare('SELECT 1 FROM class_students WHERE class_id = :cid AND student_id = :sid LIMIT 1');
    $memberStmt->execute([
        ':cid' => (int) $class['id'],
        ':sid' => (int) $user['id'],
    ]);
    $alreadyMember = (bool) $memberStmt->fetchColumn();
}

if ($class && $pdo && $isStudent && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'join_class') {
    if (!$alreadyMember) {
        try {
            $ins = $pdo->prepare('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (:cid, :sid)');
            $ins->execute([
                ':cid' => (int) $class['id'],
                ':sid' => (int) $user['id'],
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
    $teacherFirst = (string) ($class['teacher_first'] ?? '');
    $teacherLast = (string) ($class['teacher_last'] ?? '');
    $teacherName = trim($teacherFirst . ' ' . $teacherLast);
    $gradeLabel = isset($class['grade']) ? (string) $class['grade'] : '';
    $sectionLabel = (string) ($class['section'] ?? '');
    $schoolYearLabel = isset($class['school_year']) ? (string) $class['school_year'] : '';
}

?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Присъединяване към Клас - TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= time() ?>">
</head>

<body class="bg-body d-flex flex-column min-vh-100">
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-5 d-flex flex-column align-items-center justify-content-center flex-grow-1">
        <div class="w-100 animate-fade-up" style="max-width: 600px;">

            <?php if ($error): ?>
                <div class="alert alert-danger shadow-sm border-0 rounded-4 mb-4">
                    <div class="d-flex gap-3 align-items-center">
                        <i class="bi bi-exclamation-circle-fill fs-3 text-danger"></i>
                        <div>
                            <div class="fw-bold">Възникна грешка</div>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    </div>
                </div>
                <div class="text-center">
                    <a href="index.php" class="btn btn-primary rounded-pill px-4">Към началото</a>
                </div>
            <?php else: ?>

                <!-- Status Messages -->
                <?php if ($statusMessage === 'joined'): ?>
                    <div class="alert alert-success shadow-sm border-0 rounded-4 mb-4 animate-scale-in">
                        <div class="d-flex gap-3 align-items-center">
                            <i class="bi bi-check-circle-fill fs-3 text-success"></i>
                            <div>
                                <div class="fw-bold fs-5">Успех!</div>
                                <div>Присъединихте се успешно към класа.</div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($statusMessage === 'already'): ?>
                    <div class="alert alert-info shadow-sm border-0 rounded-4 mb-4 animate-scale-in">
                        <div class="d-flex gap-3 align-items-center">
                            <i class="bi bi-info-circle-fill fs-3 text-info"></i>
                            <div>
                                <div class="fw-bold">Инфо</div>
                                <div>Вие вече сте част от този клас.</div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($statusMessage === 'error'): ?>
                    <div class="alert alert-danger shadow-sm border-0 rounded-4 mb-4 animate-scale-in">
                        <div class="d-flex gap-3 align-items-center">
                            <i class="bi bi-x-circle-fill fs-3 text-danger"></i>
                            <div>Не успяхме да ви добавим в класа. Моля, опитайте отново.</div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($class): ?>
                    <div class="glass-card overflow-hidden">
                        <div class="bg-primary bg-opacity-10 p-5 text-center border-bottom border-light">
                            <div class="d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-sm mb-3"
                                style="width: 80px; height: 80px;">
                                <i class="bi bi-people-fill text-primary display-5"></i>
                            </div>
                            <h1 class="display-6 fw-bold mb-1"><?= htmlspecialchars($class['name']) ?></h1>
                            <div class="text-muted small text-uppercase tracking-wider fw-bold">Покана за клас</div>
                        </div>

                        <div class="p-5">
                            <div class="row g-4 mb-5">
                                <div class="col-md-6">
                                    <div class="p-3 bg-light bg-opacity-50 rounded-3 border h-100">
                                        <small
                                            class="text-muted text-uppercase tracking-wider fw-bold d-block mb-1">Учител</small>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-person-circle text-secondary"></i>
                                            <span class="fw-semibold"><?= htmlspecialchars($teacherName) ?: '—' ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 bg-light bg-opacity-50 rounded-3 border h-100">
                                        <small class="text-muted text-uppercase tracking-wider fw-bold d-block mb-1">Клас /
                                            Година</small>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-mortarboard-fill text-secondary"></i>
                                            <span class="fw-semibold">
                                                <?= htmlspecialchars($gradeLabel . ($sectionLabel ? ' ' . $sectionLabel : '')) ?>
                                                <span class="text-muted mx-1">•</span>
                                                <?= htmlspecialchars($schoolYearLabel) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($class['description'])): ?>
                                    <div class="col-12">
                                        <div class="p-3 bg-light bg-opacity-50 rounded-3 border">
                                            <small
                                                class="text-muted text-uppercase tracking-wider fw-bold d-block mb-1">Описание</small>
                                            <div class="text-dark"><?= htmlspecialchars($class['description']) ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-grid gap-3">
                                <?php if ($isStudent): ?>
                                    <?php if ($alreadyMember): ?>
                                        <a href="dashboard.php" class="btn btn-primary btn-lg rounded-pill shadow-lg hover-lift">
                                            <i class="bi bi-speedometer2 me-2"></i> Към таблото
                                        </a>
                                        <button disabled class="btn btn-light rounded-pill border">
                                            <i class="bi bi-check2-all me-2"></i> Вече сте записани
                                        </button>
                                    <?php else: ?>
                                        <form method="post" class="d-grid">
                                            <input type="hidden" name="__action" value="join_class" />
                                            <button type="submit"
                                                class="btn btn-primary btn-lg rounded-pill shadow-lg hover-lift py-3 fw-bold">
                                                Присъедини се към класа
                                            </button>
                                        </form>
                                        <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill">Отказ</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div
                                        class="alert alert-warning border-0 bg-warning bg-opacity-10 text-dark rounded-3 d-flex align-items-center gap-3">
                                        <i class="bi bi-person-lock fs-4 text-warning"></i>
                                        <div>Тук могат да се присъединяват само ученически акаунти.</div>
                                    </div>
                                    <a href="dashboard.php" class="btn btn-outline-primary rounded-pill">Към таблото</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="glass-card p-5 text-center">
                        <div class="mb-4 text-muted opacity-25">
                            <i class="bi bi-slash-circle display-1"></i>
                        </div>
                        <h3>Невалидна покана</h3>
                        <p class="text-muted mb-4">Кодът, който използвате, не е валиден или е изтекъл.</p>
                        <a href="index.php" class="btn btn-primary rounded-pill px-4">Начало</a>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </main>

    <?php include __DIR__ . '/components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>