<?php
session_start();

// Database config via central file
require_once __DIR__ . '/config.php';

$errors = [];
$success = null;

// Preserve posted values
$posted = [
    'role' => 'student',
    'first_name' => '',
    'last_name' => '',
    'email' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted['role'] = isset($_POST['role']) && in_array($_POST['role'], ['teacher','student'], true) ? $_POST['role'] : 'student';
    $posted['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $posted['last_name'] = trim((string)($_POST['last_name'] ?? ''));
    $posted['email'] = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password_confirm = (string)($_POST['password_confirm'] ?? '');

    // Validation
    if ($posted['first_name'] === '') { $errors[] = 'Моля, въведете име.'; }
    if ($posted['last_name'] === '') { $errors[] = 'Моля, въведете фамилия.'; }
    if ($posted['email'] === '' || !filter_var($posted['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Моля, въведете валиден имейл.'; }
    if (strlen($password) < 8) { $errors[] = 'Паролата трябва да съдържа поне 8 символа.'; }
    if ($password !== $password_confirm) { $errors[] = 'Паролите не съвпадат.'; }

    if (!$errors) {
        try {
            $pdo = db();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (role, email, password_hash, first_name, last_name) VALUES (:role, :email, :password_hash, :first_name, :last_name)');
            $stmt->execute([
                ':role' => $posted['role'],
                ':email' => mb_strtolower($posted['email']),
                ':password_hash' => $hash,
                ':first_name' => $posted['first_name'],
                ':last_name' => $posted['last_name'],
            ]);

            // Optionally log in user immediately
            $success = 'Регистрацията е успешна. Може да влезете в профила си.';
            // header('Location: login.php?registered=1'); exit;
        } catch (PDOException $e) {
            // Duplicate email
            if ($e->getCode() === '23000') {
                $errors[] = 'Съществува профил с този имейл.';
            } else {
                $errors[] = 'Грешка при свързване/запис в базата данни. Уверете се, че е импортната schema.sql и настройките са коректни.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Регистрация – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .auth-card { max-width: 720px; }
    </style>
    
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5 d-flex justify-content-center">
    <div class="card shadow-sm auth-card w-100">
        <div class="card-body p-4 p-md-5">
            <h1 class="h3 mb-3">Създаване на профил</h1>
            <p class="text-muted mb-4">Регистрирай се като ученик или учител, за да използваш пълната функционалност на платформата.</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="m-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success d-flex justify-content-between align-items-center">
                    <div><?= htmlspecialchars($success) ?></div>
                    <a class="btn btn-success btn-sm" href="login.php">Към вход</a>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Роля</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleStudent" value="student" <?= $posted['role']==='student'?'checked':'' ?> />
                                <label class="form-check-label" for="roleStudent">Ученик</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleTeacher" value="teacher" <?= $posted['role']==='teacher'?'checked':'' ?> />
                                <label class="form-check-label" for="roleTeacher">Учител</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="first_name" class="form-label">Име</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?= htmlspecialchars($posted['first_name']) ?>" required />
                    </div>
                    <div class="col-md-6">
                        <label for="last_name" class="form-label">Фамилия</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?= htmlspecialchars($posted['last_name']) ?>" required />
                    </div>
                    <div class="col-12">
                        <label for="email" class="form-label">Имейл</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($posted['email']) ?>" required />
                    </div>
                    <div class="col-md-6">
                        <label for="password" class="form-label">Парола</label>
                        <input type="password" class="form-control" id="password" name="password" minlength="8" required />
                        <div class="form-text">Минимум 8 символа.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="password_confirm" class="form-label">Потвърдете паролата</label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required />
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mt-4">
                    <div class="text-muted small">С регистрирането приемате <a href="terms.php">условията за ползване</a>.</div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Регистрация</button>
                </div>
            </form>

            <hr class="my-4" />
            <div class="d-flex align-items-center">
                <span class="me-2">Вече имате профил?</span>
                <a href="login.php">Вход</a>
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