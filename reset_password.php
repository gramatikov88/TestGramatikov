<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

function find_valid_reset(PDO $pdo, string $selector, string $token): ?array {
    $stmt = $pdo->prepare('SELECT pr.*, u.status FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.selector = :selector LIMIT 1');
    $stmt->execute([':selector' => $selector]);
    $reset = $stmt->fetch();
    if (!$reset) {
        return null;
    }
    if (!password_verify($token, (string)$reset['token_hash'])) {
        return null;
    }
    if ($reset['used_at'] !== null) {
        return null;
    }
    if (strtotime((string)$reset['expires_at']) < time()) {
        return null;
    }
    if ($reset['status'] === 'deleted') {
        return null;
    }
    return $reset;
}

$errors = [];
$selector = trim((string)($_GET['selector'] ?? $_POST['selector'] ?? ''));
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

$prefetchMessage = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $selector !== '' && $token !== '') {
    try {
        $pdo = db();
        ensure_password_resets_table($pdo);
        if (!find_valid_reset($pdo, $selector, $token)) {
            $prefetchMessage = 'The reset link is invalid or has expired. Request a new one to continue.';
        }
    } catch (Throwable $e) {
        $prefetchMessage = 'We could not verify the reset link. Please try again later.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selector = trim((string)($_POST['selector'] ?? ''));
    $token = trim((string)($_POST['token'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if ($selector === '' || $token === '') {
        $errors[] = 'The reset link is missing or incomplete.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'The new password must be at least 8 characters.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (!$errors) {
        try {
            $pdo = db();
            ensure_password_resets_table($pdo);

            $reset = find_valid_reset($pdo, $selector, $token);
            if (!$reset) {
                $errors[] = 'The reset link is invalid or has expired. Please request a new one.';
            } else {
                $pdo->beginTransaction();
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id')
                    ->execute([':hash' => $hash, ':id' => (int)$reset['user_id']]);
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')
                    ->execute([':id' => (int)$reset['id']]);
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL AND id <> :id')
                    ->execute([':uid' => (int)$reset['user_id'], ':id' => (int)$reset['id']]);
                $pdo->commit();

                $_SESSION['password_reset_success'] = true;
                header('Location: login.php');
                exit;
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Unexpected error while saving the new password. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset password · TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .auth-card { max-width: 540px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5 d-flex justify-content-center">
    <div class="card shadow-sm auth-card w-100">
        <div class="card-body p-4 p-md-5">
            <h1 class="h3 mb-3">Choose a new password</h1>
            <p class="text-muted mb-4">Pick a strong password that you have not used before. It must be at least 8 characters long.</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="m-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php elseif ($prefetchMessage): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars($prefetchMessage) ?>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="selector" value="<?= htmlspecialchars($selector) ?>" />
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>" />

                <div class="mb-3">
                    <label for="password" class="form-label">New password</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="8" required />
                </div>
                <div class="mb-4">
                    <label for="password_confirm" class="form-label">Confirm password</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required />
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <a class="small" href="forgot_password.php"><i class="bi bi-envelope me-1"></i>Request another link</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save new password</button>
                </div>
            </form>
        </div>
    </div>
</main>

<footer class="border-top py-4">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <div class="text-muted">&copy; <?= date('Y'); ?> TestGramatikov</div>
        <div class="d-flex gap-3 small">
            <a class="text-decoration-none" href="terms.php">Terms</a>
            <a class="text-decoration-none" href="privacy.php">Privacy</a>
            <a class="text-decoration-none" href="contact.php">Contact</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</footer>
</body>
</html>
