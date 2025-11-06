<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$errors = [];
$success = false;
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = trim((string)($_POST['email'] ?? ''));

    if ($emailInput === '' || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Моля, въведете валиден имейл адрес.';
    }

    if (!$errors) {
        try {
            $pdo = db();
            ensure_password_resets_table($pdo);

            $normalizedEmail = mb_strtolower($emailInput);
            $stmt = $pdo->prepare('SELECT id, status, email FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $normalizedEmail]);
            $user = $stmt->fetch();

            if ($user && $user['status'] !== 'deleted') {
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
                    ->execute([':uid' => (int)$user['id']]);

                $selector = bin2hex(random_bytes(9));
                $verifier = bin2hex(random_bytes(32));
                $tokenHash = password_hash($verifier, PASSWORD_DEFAULT);
                $expiresAt = (new DateTimeImmutable('+1 hour'));

                $pdo->prepare('INSERT INTO password_resets (user_id, selector, token_hash, expires_at, request_ip) VALUES (:user_id, :selector, :token_hash, :expires_at, :request_ip)')
                    ->execute([
                        ':user_id' => (int)$user['id'],
                        ':selector' => $selector,
                        ':token_hash' => $tokenHash,
                        ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                        ':request_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);

                $resetLink = app_url('reset_password.php?selector=' . urlencode($selector) . '&token=' . urlencode($verifier));

                $subject = 'Password reset instructions';
                $body = "Hello,\n\nWe received a request to reset the password for your TestGramatikov account. If you made this request, open the link below to set a new password:\n\n{$resetLink}\n\nThe link is valid for the next 60 minutes. If you did not make this request, you can ignore this email and your password will stay the same.\n\nTestGramatikov team";
                $recipient = is_string($user['email']) && $user['email'] !== '' ? $user['email'] : $normalizedEmail;
                send_app_mail($recipient, $subject, $body);
            }

            $success = true;
            $emailInput = '';
        } catch (Throwable $e) {
            $errors[] = 'Unexpected error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Forgot password · TestGramatikov</title>
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
            <h1 class="h3 mb-3">Забравена парола</h1>
            <p class="text-muted mb-4">Въведете имейла, с който сте регистриран. Ще получите имейл с линк за промяна на паролата.</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="m-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-envelope-check me-2"></i>
                    Ако адресът е регистриран, изпратихме инструкции за нулиране на паролата на него.
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="mb-3">
                    <label for="email" class="form-label">Email адрес</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($emailInput) ?>" required />
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <a class="small" href="login.php"><i class="bi bi-arrow-left me-1"></i>Назад към входа</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Изпрати линк</button>
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
