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
    <!--js for topBtn-->
    <script src="backToTop.js"></script>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .hero {
            background: linear-gradient(135deg, #0d6efd 0%, #6f42c1 100%);
            color: #fff;
            border-radius: 1rem;
        }

        .hero .lead {
            opacity: .95;
        }

        .category-card i {
            font-size: 1.75rem;
        }

        .glass {
            background: rgba(255, 255, 255, .9);
            box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
            border: 1px solid rgba(0, 0, 0, .05);
        }

        .brand-badge {
            background: rgba(255, 255, 255, .15);
            border: 1px solid rgba(255, 255, 255, .25);
            color: #fff;
        }
    </style>

</head>

<body>

    <?php include __DIR__ . '/components/header.php'; ?>
    <div id="top"></div>

    <main class="container my-4 my-md-5">
        <!-- Hero -->
        <section class="hero p-4 p-md-5 mb-4 mb-md-5">
            <div class="row align-items-center g-4 g-md-5">
                <div class="col-lg-7">
                    <span class="badge bg-light text-dark mb-3"></span>
                    <h1 class="display-5 fw-bold mb-3">Учи и тествай уменията си по интелигентен начин</h1>
                    <p class="lead mb-4">Платформа за бързи и адаптивни тестове – подходяща за ученици, учители и
                        самоподготовка. Избирай от готови тестове или създай свои.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="tests.php?mode=quick" class="btn btn-light btn-lg"><i
                                class="bi bi-play-fill me-1"></i>Започни бърз тест</a>
                        <a href="tests.php" class="btn btn-outline-light btn-lg"><i
                                class="bi bi-collection me-1"></i>Разгледай тестовете</a>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="glass rounded-4 p-3 p-md-4 bg-white text-dark">
                        <h5 class="mb-3"><i class="bi bi-search me-2 text-primary"></i>Търси тест</h5>
                        <form action="tests.php" method="get" class="input-group input-group-lg">
                            <input type="text" class="form-control" name="q"
                                placeholder="Ключова дума, тема или клас..." aria-label="Търсене на тестове" />
                            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                        </form>
                        <div class="small text-muted mt-2">Популярни:
                            <?= htmlspecialchars($popularStr ?: 'граматика, лексика, пунктуация, математика') ?>
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
                            <h5 class="card-title">Адаптивни тестове</h5>
                            <p class="card-text">Въпросите се напасват според твоето ниво за максимално ефективно учене.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="text-primary mb-2"><i class="bi bi-graph-up-arrow fs-3"></i></div>
                            <h5 class="card-title">Статистика и напредък</h5>
                            <p class="card-text">Следи резултатите си по теми и откривай къде да се фокусираш.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="text-primary mb-2"><i class="bi bi-magic fs-3"></i></div>
                            <h5 class="card-title">Създаване на тестове</h5>
                            <p class="card-text">Учители и автори могат лесно да създават, споделят и оценяват.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Categories -->
        <section class="mb-5">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h4 class="m-0">Категории</h4>
                <a href="categories.php" class="btn btn-sm btn-outline-secondary">Всички категории</a>
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
                                        <div class="text-muted small">Тестове: <?= (int) $c['tests_count'] ?></div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="text-muted">Няма налични категории.</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Recent tests (from DB) -->
        <section class="mb-5">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h4 class="m-0">Последни тестове</h4>
                <a href="tests.php?sort=updated_desc" class="btn btn-sm btn-outline-secondary">Виж всички</a>
            </div>
            <div class="list-group shadow-sm">
                <?php if ($recentTests): ?>
                    <?php foreach ($recentTests as $rt): ?>
                        <a href="test_view.php?test_id=<?= (int) $rt['id'] ?>"
                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <?= htmlspecialchars($rt['title']) ?>
                            <span class="badge bg-primary rounded-pill"><?= (int) ($rt['qcount'] ?? 0) ?> въпроса</span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="list-group-item text-muted">Няма налични тестове.</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- How it works -->
        <section class="mb-5">
            <h4 class="mb-3">Как работи?</h4>
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0">
                        <div class="card-body">
                            <div class="display-6 text-primary">1</div>
                            <div class="fw-semibold mt-2">Избери категория или тема</div>
                            <div class="text-muted">Намери подходящ тест според нивото ти.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0">
                        <div class="card-body">
                            <div class="display-6 text-primary">2</div>
                            <div class="fw-semibold mt-2">Отговори на въпросите</div>
                            <div class="text-muted">Получаваш мигновена обратна връзка и подсказки.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0">
                        <div class="card-body">
                            <div class="display-6 text-primary">3</div>
                            <div class="fw-semibold mt-2">Проследявай напредъка</div>
                            <div class="text-muted">Виж статистика и препоръки за следващи стъпки.</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <button onclick="topFunction()" id="topBtn" title="go to top">↑</button>
    <style>
        #topBtn {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 30px;
            z-index: 99;
            border: none;
            outline: none;
            background-color: red;
            color: white;
            cursor: pointer;
            padding: 15px;
            border-radius: 10px;
            font-size: 18px;
        }

        #topBtn::hover {
            background-color: #555;
        }
    </style>

    <footer class="border-top py-4">
        <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <div class="text-muted">&copy; <?php echo date('Y'); ?> TestGramatikov</div>
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