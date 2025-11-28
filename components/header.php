<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
?>
<!-- Theme Script -->
<script>
    (function() {
        try {
            var saved = localStorage.getItem('tg-theme');
            if (!saved) {
                saved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', saved);
        } catch (e) {}
    })();
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/theme.css')) ?>">

<header class="border-bottom bg-body sticky-top">
    <nav class="navbar navbar-expand-lg shadow-sm py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <span class="brand-badge d-inline-flex align-items-center justify-content-center">
                    <i class="bi bi-lightning-charge-fill"></i>
                </span>
                <span class="fw-bold">TestGramatikov</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-center gap-2">
                    <li class="nav-item"><a class="nav-link" href="index.php">Начало</a></li>
                    <li class="nav-item"><a class="nav-link" href="tests.php">Тестове</a></li>
                    <li class="nav-item"><a class="nav-link" href="categories.php">Категории</a></li>
                    
                    <!-- Theme Switcher -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="themeMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-circle-half me-1"></i> Тема
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" aria-labelledby="themeMenu">
                            <li><button class="dropdown-item d-flex align-items-center" type="button" data-theme-value="light"><i class="bi bi-brightness-high me-2"></i>Светла</button></li>
                            <li><button class="dropdown-item d-flex align-items-center" type="button" data-theme-value="dark"><i class="bi bi-moon-stars me-2"></i>Тъмна</button></li>
                        </ul>
                    </li>

                    <!-- Help Toggle -->
                    <li class="nav-item d-flex align-items-center ms-2">
                        <div class="form-check form-switch m-0" title="Включи/Изключи помощник">
                            <input class="form-check-input" type="checkbox" role="switch" id="navHelpToggle" style="cursor: pointer;">
                            <label class="form-check-label small text-muted ms-1" for="navHelpToggle" style="cursor: pointer;"><i class="bi bi-question-circle-fill"></i></label>
                        </div>
                    </li>

                    <li class="nav-item border-start mx-2 d-none d-lg-block"></li>

                    <?php if (!empty($_SESSION['user'])): ?>
                        <li class="nav-item"><a class="btn btn-primary" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Табло</a></li>
                        <li class="nav-item d-flex align-items-center">
                            <span class="text-muted small me-2">Здравей,</span>
                            <span class="fw-semibold"><?= htmlspecialchars($_SESSION['user']['first_name']) ?></span>
                        </li>
                        <li class="nav-item"><a class="btn btn-outline-danger btn-sm" href="logout.php"><i class="bi bi-box-arrow-right"></i></a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="btn btn-outline-primary" href="login.php">Вход</a></li>
                        <li class="nav-item"><a class="btn btn-primary" href="register.php">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
</header>

<!-- Back to Top -->
<button type="button" id="backToTopBtn" class="back-to-top shadow" aria-label="Върни се в началото">
    <i class="bi bi-arrow-up"></i>
</button>

<script>
    (function() {
        function setTheme(val) {
            document.documentElement.setAttribute('data-bs-theme', val);
            try {
                localStorage.setItem('tg-theme', val);
            } catch (e) {}
        }
        document.querySelectorAll('[data-theme-value]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setTheme(btn.getAttribute('data-theme-value'));
            });
        });
    })();
</script>
<script src="backToTop.js" defer></script>

<script>
    window.currentUserRole = '<?= $_SESSION['user']['role'] ?? 'student' ?>';
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.0.1/dist/driver.css"/>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.0.1/dist/driver.js.iife.js"></script>
<script src="assets/js/tutorial.js" defer></script>
