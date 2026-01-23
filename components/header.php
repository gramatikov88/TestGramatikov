<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Helper loader if not already loaded
if (!function_exists('percent')) {
    $helperPath = __DIR__ . '/../lib/helpers.php';
    if (file_exists($helperPath)) {
        require_once $helperPath;
    }
}
require_once __DIR__ . '/../config.php';
?>
<!-- Theme Script -->
<script>
    (function () {
        try {
            var saved = localStorage.getItem('tg-theme');
            if (!saved) {
                saved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', saved);
        } catch (e) { }
    })();
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/theme.css')) ?>?v=<?= time() ?>">

<header class="sticky-top glass-header" style="z-index: 9999;">
    <nav class="navbar navbar-expand-lg py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <span
                    class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle"
                    style="width: 40px; height: 40px;">
                    <i class="bi bi-lightning-charge-fill fs-5"></i>
                </span>
                <span class="fw-bold fs-4 tracking-tight">TestGramatikov</span>
            </a>
            <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse"
                data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-center gap-1 gap-lg-3 mt-3 mt-lg-0">
                    <li class="nav-item"><a class="nav-link fw-medium" href="index.php">Начало</a></li>
                    <li class="nav-item"><a class="nav-link fw-medium" href="tests.php">Тестове</a></li>
                    <li class="nav-item"><a class="nav-link fw-medium" href="categories.php">Категории</a></li>

                    <!-- Theme Switcher -->
                    <li class="nav-item dropdown">
                        <button
                            class="btn btn-link nav-link dropdown-toggle d-flex align-items-center text-decoration-none"
                            id="themeMenu" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-circle-half fs-5"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end glass-card border-0 p-2" aria-labelledby="themeMenu">
                            <li><button class="dropdown-item rounded-2 d-flex align-items-center gap-2" type="button"
                                    data-theme-value="light"><i class="bi bi-brightness-high"></i> Светла</button></li>
                            <li><button class="dropdown-item rounded-2 d-flex align-items-center gap-2" type="button"
                                    data-theme-value="dark"><i class="bi bi-moon-stars"></i> Тъмна</button></li>
                        </ul>
                    </li>

                    <li class="nav-item border-start mx-2 d-none d-lg-block h-50 opacity-25"></li>

                    <?php if (!empty($_SESSION['user'])): ?>
                        <li class="nav-item"><a class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm"
                                href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Табло</a></li>
                        <!-- Teacher Manage Menu -->
                        <?php if ($_SESSION['user']['role'] === 'teacher'): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle fw-medium" href="#" role="button" data-bs-toggle="dropdown">
                                    Управление
                                </a>
                                <ul class="dropdown-menu glass-card border-0 p-2 shadow-lg">
                                    <li><a class="dropdown-item rounded-2" href="assignments.php?view=history"><i
                                                class="bi bi-clock-history me-2 text-muted"></i> Архив Задания</a></li>
                                    <li><a class="dropdown-item rounded-2" href="tests.php"><i
                                                class="bi bi-file-earmark-text me-2 text-muted"></i> Моите Тестове</a></li>
                                    <li>
                                        <hr class="dropdown-divider opacity-10">
                                    </li>
                                    <li><a class="dropdown-item rounded-2" href="classes_create.php"><i
                                                class="bi bi-plus-circle me-2 text-primary"></i> Нов Клас</a></li>
                                    <li><a class="dropdown-item rounded-2" href="createTest.php"><i
                                                class="bi bi-magic me-2 text-primary"></i> Нов Тест</a></li>
                                </ul>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button"
                                data-bs-toggle="dropdown" aria-expanded="false">
                                <span
                                    class="avatar-initials rounded-circle bg-primary bg-opacity-10 text-primary fw-bold d-flex align-items-center justify-content-center"
                                    style="width: 32px; height: 32px; font-size: 0.8rem;">
                                    <?= mb_substr($_SESSION['user']['first_name'] ?? 'U', 0, 1) ?>
                                </span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end glass-card border-0 p-2 shadow-lg">
                                <li>
                                    <h6 class="dropdown-header">
                                        <?= htmlspecialchars($_SESSION['user']['first_name'] . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
                                    </h6>
                                </li>
                                <li>
                                    <hr class="dropdown-divider opacity-10">
                                </li>
                                <li><a class="dropdown-item rounded-2 text-danger d-flex align-items-center gap-2"
                                        href="logout.php"><i class="bi bi-box-arrow-right"></i> Изход</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a
                                class="btn btn-glass btn-sm rounded-pill px-3 fw-medium text-primary border-primary border-opacity-25"
                                href="login.php">Вход</a></li>
                        <li class="nav-item"><a class="btn btn-primary btn-sm rounded-pill px-3 fw-medium shadow-sm"
                                href="register.php">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
</header>

<!-- Back to Top -->
<button type="button" id="backToTopBtn"
    class="btn btn-primary rounded-circle shadow-lg position-fixed z-3 d-none align-items-center justify-content-center"
    style="bottom: 2rem; right: 2rem; width: 3rem; height: 3rem;" aria-label="Върни се в началото">
    <i class="bi bi-arrow-up"></i>
</button>

<script>
    (function () {
        function setTheme(val) {
            document.documentElement.setAttribute('data-bs-theme', val);
            try {
                localStorage.setItem('tg-theme', val);
            } catch (e) { }
        }
        document.querySelectorAll('[data-theme-value]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setTheme(btn.getAttribute('data-theme-value'));
            });
        });

        // Back to top logic
        const btn = document.getElementById('backToTopBtn');
        if (btn) {
            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) btn.classList.remove('d-none'), btn.classList.add('d-flex');
                else btn.classList.add('d-none'), btn.classList.remove('d-flex');
            });
            btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
        }
    })();
</script>

<?php if (isset($_SESSION['user']) && ($_SESSION['user']['role'] ?? '') === 'student'): ?>
    <!-- Tutorial support for students -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.0.1/dist/driver.css" />
    <script src="https://cdn.jsdelivr.net/npm/driver.js@1.0.1/dist/driver.js.iife.js"></script>
    <script>window.currentUserRole = 'student';</script>
<?php endif; ?>