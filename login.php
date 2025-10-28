<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$nextRaw = '';
if (isset($_POST['next'])) {
    $nextRaw = (string)$_POST['next'];
} elseif (isset($_GET['next'])) {
    $nextRaw = (string)$_GET['next'];
} elseif (!empty($_SESSION['after_login_redirect'])) {
    $nextRaw = (string)$_SESSION['after_login_redirect'];
}
$next = sanitize_redirect_path($nextRaw);
if ($next !== '') {
    $_SESSION['after_login_redirect'] = $next;
} else {
    unset($_SESSION['after_login_redirect']);
}

$errors = [];
$posted = [
    'email' => '',
];

$resetSuccess = !empty($_SESSION['password_reset_success'] ?? null);
if ($resetSuccess) {
    unset($_SESSION['password_reset_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted['email'] = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($posted['email'] === '' || !filter_var($posted['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Моля, въведете валиден имейл.';
    }
    if ($password === '') {
        $errors[] = 'Моля, въведете парола.';
    }

    if (!$errors) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT id, role, email, password_hash, first_name, last_name, status FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => mb_strtolower($posted['email'])]);
            $user = $stmt->fetch();

            if (!$user) {
                $errors[] = 'Невалидни данни за вход.';
            } elseif ($user['status'] !== 'active') {
                $errors[] = 'Профилът е неактивен. Свържете се с администратор.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                $errors[] = 'Невалидни данни за вход.';
            } else {
                // Success: set session, update last_login
                $_SESSION['user'] = [
                    'id' => (int)$user['id'],
                    'role' => $user['role'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                ];
                $upd = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
                $upd->execute([':id' => (int)$user['id']]);

                $redirectTo = $next !== '' ? $next : 'index.php';
                unset($_SESSION['after_login_redirect']);
                header('Location: ' . $redirectTo);
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Грешка при връзка с базата. Проверете конфигурацията.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Вход – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .auth-card { max-width: 540px; }
    </style>
    
    </head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5 d-flex justify-content-center">
    <div class="card shadow-sm auth-card w-100">
        <div class="card-body p-4 p-md-5">
            <h1 class="h3 mb-3">Вход</h1>
            <p class="text-muted mb-4">Въведете имейл и парола, за да продължите.</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="m-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php elseif ($resetSuccess): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check2-circle me-2"></i>Your password was updated. Please sign in with the new password.
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>" />
                <div class="mb-3">
                    <label for="email" class="form-label">Имейл</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($posted['email']) ?>" required />
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Парола</label>
                    <input type="password" class="form-control" id="password" name="password" required />
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <a class="small" href="forgot_password.php">Forgot your password?</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i>Вход</button>
                </div>
            </form>

            <hr class="my-4" />
            <div class="d-flex align-items-center">
                <span class="me-2">Нямате профил?</span>
                <a href="register.php">Регистрация</a>
            </div>
        </div>
    </div>
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
</footer>
</body>
</html>
