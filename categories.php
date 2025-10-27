<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = db();
ensure_subjects_scope($pdo);

$user = $_SESSION['user'] ?? null;
$role = $user['role'] ?? 'guest';

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$rows = [];
try {
    if ($role === 'teacher') {
        // Teacher: list own subjects with count of own tests
        $sql = 'SELECT s.id, s.name, COUNT(t.id) AS tests_count
                FROM subjects s
                LEFT JOIN tests t ON t.subject_id = s.id AND t.owner_teacher_id = :tid
                WHERE s.owner_teacher_id = :tid';
        $params = [':tid' => (int)$user['id']];
        if ($q !== '') { $sql .= ' AND s.name LIKE :q'; $params[':q'] = '%'.$q.'%'; }
        $sql .= ' GROUP BY s.id, s.name ORDER BY s.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        // Student/Guest: subjects that have shared tests (global)
        $sql = 'SELECT s.id, s.name, COUNT(t.id) AS tests_count
                FROM subjects s
                JOIN tests t ON t.subject_id = s.id AND t.visibility = "shared" AND t.status = "published"';
        $params = [];
        if ($q !== '') { $sql .= ' WHERE s.name LIKE :q'; $params[':q'] = '%'.$q.'%'; }
        $sql .= ' GROUP BY s.id, s.name HAVING tests_count > 0 ORDER BY s.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $rows = [];
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Категории – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .category-card i { font-size: 1.75rem; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 m-0">Категории</h1>
        <form method="get" class="d-flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Търсене на предмет..." />
            <button class="btn btn-outline-secondary" type="submit">Търси</button>
        </form>
    </div>

    <?php if ($role === 'teacher'): ?>
        <div class="alert alert-light border">Показани са вашите предмети. Може да управлявате от <a href="subjects_create.php">Предмети</a>.</div>
    <?php else: ?>
        <div class="alert alert-light border">Показани са предмети, по които има споделени публикувани тестове.</div>
    <?php endif; ?>

    <div class="row g-3 g-md-4">
        <?php if (!$rows): ?>
            <div class="col-12"><div class="text-muted">Няма намерени категории.</div></div>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <div class="col-6 col-md-3">
                <a class="text-decoration-none" href="tests.php?subject_id=<?= (int)$r['id'] ?>">
                    <div class="card category-card h-100 shadow-sm">
                        <div class="card-body text-center">
                            <i class="bi bi-journal-bookmark text-primary"></i>
                            <div class="fw-semibold mt-2 text-primary"><?= htmlspecialchars($r['name']) ?></div>
                            <div class="text-muted small">Тестове: <?= (int)$r['tests_count'] ?></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
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
