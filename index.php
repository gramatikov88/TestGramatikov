<?php
session_start();
require_once __DIR__ . '/config.php';
$pdo = null;
$homeCategories = [];
$recentTests = [];
$popularStr = '';
try {
    $pdo = db();
    ensure_subjects_scope($pdo);
    $user = $_SESSION['user'] ?? null;
    $role = $user['role'] ?? 'guest';

    // Categories for homepage (top by tests count)
    if ($role === 'teacher') {
        $stmt = $pdo->prepare('SELECT s.id, s.name, COUNT(t.id) AS tests_count
                               FROM subjects s
                               LEFT JOIN tests t ON t.subject_id = s.id AND t.owner_teacher_id = :tid
                               WHERE s.owner_teacher_id = :tid
                               GROUP BY s.id, s.name
                               ORDER BY tests_count DESC, s.name
                               LIMIT 8');
        $stmt->execute([':tid' => (int) $user['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT s.id, s.name, COUNT(t.id) AS tests_count
                               FROM subjects s
                               JOIN tests t ON t.subject_id = s.id AND t.visibility = "shared" AND t.status = "published"
                               GROUP BY s.id, s.name
                               ORDER BY tests_count DESC, s.name
                               LIMIT 8');
        $stmt->execute();
    }
    $homeCategories = $stmt->fetchAll();
    if ($homeCategories) {
        $popularStr = implode(', ', array_map(fn($r) => $r['name'], array_slice($homeCategories, 0, 4)));
    }

    // Recent tests for homepage
    if ($role === 'teacher') {
        $stmt = $pdo->prepare('SELECT t.id, t.title,
                                      (SELECT COUNT(*) FROM test_questions tq WHERE tq.test_id = t.id) AS qcount
                               FROM tests t
                               WHERE t.owner_teacher_id = :tid
                               ORDER BY t.updated_at DESC
                               LIMIT 5');
        $stmt->execute([':tid' => (int) $user['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT t.id, t.title,
                                      (SELECT COUNT(*) FROM test_questions tq WHERE tq.test_id = t.id) AS qcount
                               FROM tests t
                               WHERE t.visibility = "shared" AND t.status = "published"
                               ORDER BY t.updated_at DESC
                               LIMIT 5');
        $stmt->execute();
    }
    $recentTests = $stmt->fetchAll();
} catch (Throwable $e) {
    // leave placeholders if DB not reachable
}
?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>TestGramatikov</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    

</head>

<body>

    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-4 my-md-5">
        <!-- Hero -->
        <section class="tg-hero p-4 p-md-5 mb-4 mb-md-5">
            <div class="row align-items-center g-4 g-md-5">
                <div class="col-lg-7">
                    <span class="badge bg-light text-dark mb-3">Ð˜Ð½Ñ‚ÐµÑ€Ð°ÐºÑ‚Ð¸Ð²Ð½Ð¸ Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ â€¢ Ð¡Ñ‚Ð°Ñ‚Ð¸ÑÑ‚Ð¸ÐºÐ° â€¢ ÐÐ°Ð¿Ñ€ÐµÐ´ÑŠÐº</span>
                    <h1 class="display-5 fw-bold mb-3">Ð£Ñ‡Ð¸ Ð¸ Ñ‚ÐµÑÑ‚Ð²Ð°Ð¹ ÑƒÐ¼ÐµÐ½Ð¸ÑÑ‚Ð° ÑÐ¸ Ð¿Ð¾ Ð¸Ð½Ñ‚ÐµÐ»Ð¸Ð³ÐµÐ½Ñ‚ÐµÐ½ Ð½Ð°Ñ‡Ð¸Ð½</h1>
                    <p class="lead mb-4">ÐŸÐ»Ð°Ñ‚Ñ„Ð¾Ñ€Ð¼Ð° Ð·Ð° Ð±ÑŠÑ€Ð·Ð¸ Ð¸ Ð°Ð´Ð°Ð¿Ñ‚Ð¸Ð²Ð½Ð¸ Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ â€“ Ð¿Ð¾Ð´Ñ…Ð¾Ð´ÑÑ‰Ð° Ð·Ð° ÑƒÑ‡ÐµÐ½Ð¸Ñ†Ð¸, ÑƒÑ‡Ð¸Ñ‚ÐµÐ»Ð¸ Ð¸
                        ÑÐ°Ð¼Ð¾Ð¿Ð¾Ð´Ð³Ð¾Ñ‚Ð¾Ð²ÐºÐ°. Ð˜Ð·Ð±Ð¸Ñ€Ð°Ð¹ Ð¾Ñ‚ Ð³Ð¾Ñ‚Ð¾Ð²Ð¸ Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ Ð¸Ð»Ð¸ ÑÑŠÐ·Ð´Ð°Ð¹ ÑÐ²Ð¾Ð¸.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="tests.php?mode=quick" class="btn btn-light btn-lg"><i
                                class="bi bi-play-fill me-1"></i>Ð—Ð°Ð¿Ð¾Ñ‡Ð½Ð¸ Ð±ÑŠÑ€Ð· Ñ‚ÐµÑÑ‚</a>
                        <a href="tests.php" class="btn btn-outline-light btn-lg"><i
                                class="bi bi-collection me-1"></i>Ð Ð°Ð·Ð³Ð»ÐµÐ´Ð°Ð¹ Ñ‚ÐµÑÑ‚Ð¾Ð²ÐµÑ‚Ðµ</a>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="glass rounded-4 p-3 p-md-4 bg-white text-dark">
                        <h5 class="mb-3"><i class="bi bi-search me-2 text-primary"></i>Ð¢ÑŠÑ€ÑÐ¸ Ñ‚ÐµÑÑ‚</h5>
                        <form action="tests.php" method="get" class="input-group input-group-lg">
                            <input type="text" class="form-control" name="q"
                                placeholder="ÐšÐ»ÑŽÑ‡Ð¾Ð²Ð° Ð´ÑƒÐ¼Ð°, Ñ‚ÐµÐ¼Ð° Ð¸Ð»Ð¸ ÐºÐ»Ð°Ñ..." aria-label="Ð¢ÑŠÑ€ÑÐµÐ½Ðµ Ð½Ð° Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ" />
                            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                        </form>
                        <div class="small text-muted mt-2">ÐŸÐ¾Ð¿ÑƒÐ»ÑÑ€Ð½Ð¸:
                            <?= htmlspecialchars($popularStr ?: 'Ð³Ñ€Ð°Ð¼Ð°Ñ‚Ð¸ÐºÐ°, Ð»ÐµÐºÑÐ¸ÐºÐ°, Ð¿ÑƒÐ½ÐºÑ‚ÑƒÐ°Ñ†Ð¸Ñ, Ð¼Ð°Ñ‚ÐµÐ¼Ð°Ñ‚Ð¸ÐºÐ°') ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="mb-5">
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="text-primary mb-2"><i class="bi bi-emoji-sunglasses fs-3"></i></div>
                            <h5 class="card-title">ÐÐ´Ð°Ð¿Ñ‚Ð¸Ð²Ð½Ð¸ Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ</h5>
                            <p class="card-text">Ð’ÑŠÐ¿Ñ€Ð¾ÑÐ¸Ñ‚Ðµ ÑÐµ Ð½Ð°Ð¿Ð°ÑÐ²Ð°Ñ‚ ÑÐ¿Ð¾Ñ€ÐµÐ´ Ñ‚Ð²Ð¾ÐµÑ‚Ð¾ Ð½Ð¸Ð²Ð¾ Ð·Ð° Ð¼Ð°ÐºÑÐ¸Ð¼Ð°Ð»Ð½Ð¾ ÐµÑ„ÐµÐºÑ‚Ð¸Ð²Ð½Ð¾ ÑƒÑ‡ÐµÐ½Ðµ.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="text-primary mb-2"><i class="bi bi-graph-up-arrow fs-3"></i></div>
                            <h5 class="card-title">Ð¡Ñ‚Ð°Ñ‚Ð¸ÑÑ‚Ð¸ÐºÐ° Ð¸ Ð½Ð°Ð¿Ñ€ÐµÐ´ÑŠÐº</h5>
                            <p class="card-text">Ð¡Ð»ÐµÐ´Ð¸ Ñ€ÐµÐ·ÑƒÐ»Ñ‚Ð°Ñ‚Ð¸Ñ‚Ðµ ÑÐ¸ Ð¿Ð¾ Ñ‚ÐµÐ¼Ð¸ Ð¸ Ð¾Ñ‚ÐºÑ€Ð¸Ð²Ð°Ð¹ ÐºÑŠÐ´Ðµ Ð´Ð° ÑÐµ Ñ„Ð¾ÐºÑƒÑÐ¸Ñ€Ð°Ñˆ.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="text-primary mb-2"><i class="bi bi-magic fs-3"></i></div>
                            <h5 class="card-title">Ð¡ÑŠÐ·Ð´Ð°Ð²Ð°Ð½Ðµ Ð½Ð° Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ</h5>
                            <p class="card-text">Ð£Ñ‡Ð¸Ñ‚ÐµÐ»Ð¸ Ð¸ Ð°Ð²Ñ‚Ð¾Ñ€Ð¸ Ð¼Ð¾Ð³Ð°Ñ‚ Ð»ÐµÑÐ½Ð¾ Ð´Ð° ÑÑŠÐ·Ð´Ð°Ð²Ð°Ñ‚, ÑÐ¿Ð¾Ð´ÐµÐ»ÑÑ‚ Ð¸ Ð¾Ñ†ÐµÐ½ÑÐ²Ð°Ñ‚.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Categories -->
        <section class="mb-5">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h4 class="m-0">ÐšÐ°Ñ‚ÐµÐ³Ð¾Ñ€Ð¸Ð¸</h4>
                <a href="categories.php" class="btn btn-sm btn-outline-secondary">Ð’ÑÐ¸Ñ‡ÐºÐ¸ ÐºÐ°Ñ‚ÐµÐ³Ð¾Ñ€Ð¸Ð¸</a>
            </div>
            <div class="row g-3 g-md-4">
                <?php if ($homeCategories): ?>
                    <?php foreach ($homeCategories as $c): ?>
                        <div class="col-6 col-md-3">
                            <a class="text-decoration-none" href="tests.php?subject_id=<?= (int) $c['id'] ?>">
                                <div class="card category-card h-100 shadow-sm">
                                    <div class="card-body text-center">
                                        <i class="bi bi-journal-bookmark text-primary"></i>
                                        <div class="fw-semibold mt-2 text-dark"><?= htmlspecialchars($c['name']) ?></div>
                                        <div class="text-muted small">Ð¢ÐµÑÑ‚Ð¾Ð²Ðµ: <?= (int) $c['tests_count'] ?></div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="text-muted">ÐÑÐ¼Ð° Ð½Ð°Ð»Ð¸Ñ‡Ð½Ð¸ ÐºÐ°Ñ‚ÐµÐ³Ð¾Ñ€Ð¸Ð¸.</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Recent tests (from DB) -->
        <section class="mb-5">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h4 class="m-0">ÐŸÐ¾ÑÐ»ÐµÐ´Ð½Ð¸ Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ</h4>
                <a href="tests.php?sort=updated_desc" class="btn btn-sm btn-outline-secondary">Ð’Ð¸Ð¶ Ð²ÑÐ¸Ñ‡ÐºÐ¸</a>
            </div>
            <div class="list-group shadow-sm">
                <?php if ($recentTests): ?>
                    <?php foreach ($recentTests as $rt): ?>
                        <a href="test_view.php?test_id=<?= (int) $rt['id'] ?>"
                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <?= htmlspecialchars($rt['title']) ?>
                            <span class="badge bg-primary rounded-pill"><?= (int) ($rt['qcount'] ?? 0) ?> Ð²ÑŠÐ¿Ñ€Ð¾ÑÐ°</span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="list-group-item text-muted">ÐÑÐ¼Ð° Ð½Ð°Ð»Ð¸Ñ‡Ð½Ð¸ Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ.</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- How it works -->
        <section class="mb-5">
            <h4 class="mb-3">ÐšÐ°Ðº Ñ€Ð°Ð±Ð¾Ñ‚Ð¸?</h4>
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0">
                        <div class="card-body">
                            <div class="display-6 text-primary">1</div>
                            <div class="fw-semibold mt-2">Ð˜Ð·Ð±ÐµÑ€Ð¸ ÐºÐ°Ñ‚ÐµÐ³Ð¾Ñ€Ð¸Ñ Ð¸Ð»Ð¸ Ñ‚ÐµÐ¼Ð°</div>
                            <div class="text-muted">ÐÐ°Ð¼ÐµÑ€Ð¸ Ð¿Ð¾Ð´Ñ…Ð¾Ð´ÑÑ‰ Ñ‚ÐµÑÑ‚ ÑÐ¿Ð¾Ñ€ÐµÐ´ Ð½Ð¸Ð²Ð¾Ñ‚Ð¾ Ñ‚Ð¸.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0">
                        <div class="card-body">
                            <div class="display-6 text-primary">2</div>
                            <div class="fw-semibold mt-2">ÐžÑ‚Ð³Ð¾Ð²Ð¾Ñ€Ð¸ Ð½Ð° Ð²ÑŠÐ¿Ñ€Ð¾ÑÐ¸Ñ‚Ðµ</div>
                            <div class="text-muted">ÐŸÐ¾Ð»ÑƒÑ‡Ð°Ð²Ð°Ñˆ Ð¼Ð¸Ð³Ð½Ð¾Ð²ÐµÐ½Ð° Ð¾Ð±Ñ€Ð°Ñ‚Ð½Ð° Ð²Ñ€ÑŠÐ·ÐºÐ° Ð¸ Ð¿Ð¾Ð´ÑÐºÐ°Ð·ÐºÐ¸.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0">
                        <div class="card-body">
                            <div class="display-6 text-primary">3</div>
                            <div class="fw-semibold mt-2">ÐŸÑ€Ð¾ÑÐ»ÐµÐ´ÑÐ²Ð°Ð¹ Ð½Ð°Ð¿Ñ€ÐµÐ´ÑŠÐºÐ°</div>
                            <div class="text-muted">Ð’Ð¸Ð¶ ÑÑ‚Ð°Ñ‚Ð¸ÑÑ‚Ð¸ÐºÐ° Ð¸ Ð¿Ñ€ÐµÐ¿Ð¾Ñ€ÑŠÐºÐ¸ Ð·Ð° ÑÐ»ÐµÐ´Ð²Ð°Ñ‰Ð¸ ÑÑ‚ÑŠÐ¿ÐºÐ¸.</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-top py-4">
        <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <div class="text-muted">&copy; <?php echo date('Y'); ?> TestGramatikov</div>
            <div class="d-flex gap-3 small">
                <a class="text-decoration-none" href="terms.php">Ð£ÑÐ»Ð¾Ð²Ð¸Ñ</a>
                <a class="text-decoration-none" href="privacy.php">ÐŸÐ¾Ð²ÐµÑ€Ð¸Ñ‚ÐµÐ»Ð½Ð¾ÑÑ‚</a>
                <a class="text-decoration-none" href="contact.php">ÐšÐ¾Ð½Ñ‚Ð°ÐºÑ‚</a>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    </footer>
</body>

</html>

