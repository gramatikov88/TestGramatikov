<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config.php';
?>
<script>
// Apply saved or system theme early to avoid flash
(function(){
  try{
    var saved = localStorage.getItem('tg-theme');
    if(!saved){ saved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'; }
    document.documentElement.setAttribute('data-bs-theme', saved);
  }catch(e){}
})();
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/theme.css')) ?>">

<header class="border-bottom bg-body">
  <nav class="navbar navbar-expand-lg container py-3">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <span class="brand-badge d-inline-flex align-items-center justify-content-center">
        <i class="bi bi-lightning-charge-fill"></i>
      </span>
      <strong>TestGramatikov</strong>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="index.php">Начало</a></li>
        <li class="nav-item"><a class="nav-link" href="tests.php">Тестове</a></li>
        <li class="nav-item"><a class="nav-link" href="categories.php">Категории</a></li>
        <!-- Theme switcher -->
        <li class="nav-item dropdown ms-lg-3">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="themeMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-circle-half me-1"></i> Тема
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="themeMenu">
            <li><button class="dropdown-item" type="button" data-theme-value="light"><i class="bi bi-brightness-high me-2"></i>Светла</button></li>
            <li><button class="dropdown-item" type="button" data-theme-value="dark"><i class="bi bi-moon-stars me-2"></i>Тъмна</button></li>
          </ul>
        </li>
        <?php if (!empty($_SESSION['user'])): ?>
          <li class="nav-item ms-lg-3"><a class="btn btn-primary" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Табло</a></li>
          <li class="nav-item d-flex align-items-center ms-lg-3"><span class="text-muted small me-2">Здравей,</span><span class="fw-semibold"><?= htmlspecialchars($_SESSION['user']['first_name'] . ' ' . $_SESSION['user']['last_name']) ?></span></li>
          <li class="nav-item ms-2"><a class="btn btn-outline-danger" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Изход</a></li>
        <?php else: ?>
          <li class="nav-item ms-lg-3"><a class="btn btn-outline-primary" href="login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Вход</a></li>
          <li class="nav-item ms-2"><a class="btn btn-primary" href="register.php"><i class="bi bi-person-plus me-1"></i>Регистрация</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </nav>
</header>

<button type="button" id="backToTopBtn" class="back-to-top" aria-label="Върни се в началото">
  <i class="bi bi-arrow-up"></i>
</button>

<script>
(function(){
  function setTheme(val){
    document.documentElement.setAttribute('data-bs-theme', val);
    try{ localStorage.setItem('tg-theme', val);}catch(e){}
  }
  document.querySelectorAll('[data-theme-value]').forEach(function(btn){
    btn.addEventListener('click', function(){ setTheme(btn.getAttribute('data-theme-value')); });
  });
})();
</script>
<script src="backToTop.js" defer></script>
