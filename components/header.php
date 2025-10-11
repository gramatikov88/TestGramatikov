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
<style>
/* Soft beige theme variables */
[data-bs-theme="soft"]{
  --bs-body-bg:#f5efe6;
  --bs-body-color:#2b2b2b;
  --bs-border-color:#e4dacb;
  --bs-link-color:#0d6efd; --bs-link-hover-color:#0b5ed7;
  /* Navbar */
  --bs-navbar-bg:transparent; --bs-navbar-color:#2b2b2b; --bs-navbar-hover-color:#0d6efd; --bs-navbar-brand-color:#2b2b2b; --bs-navbar-toggler-border-color:#d7cfc3;
  /* Cards */
  --bs-card-bg:#fff7ec; --bs-card-border-color:#e8dfd0;
  /* Dropdown */
  --bs-dropdown-bg:#fff8ef; --bs-dropdown-color:#2b2b2b; --bs-dropdown-border-color:#e8dfd0; --bs-dropdown-link-color:#2b2b2b; --bs-dropdown-link-hover-bg:rgba(13,110,253,.08); --bs-dropdown-link-hover-color:#0d6efd;
  /* Inputs */
  --bs-form-control-bg:#ffffff; --bs-input-bg:#ffffff; --bs-input-border-color:#e2d9ca;
}
/* Map legacy utilities to soft theme */
[data-bs-theme="soft"] .bg-white{ background-color: var(--bs-card-bg) !important; }
[data-bs-theme="soft"] .border-bottom{ border-bottom-color: var(--bs-border-color) !important; }
[data-bs-theme="soft"] .border-top{ border-top-color: var(--bs-border-color) !important; }
/* Dark theme adjustments for legacy classes and headers */
[data-bs-theme="dark"] .bg-white{ background-color: var(--bs-card-bg) !important; }
[data-bs-theme="dark"] .border-bottom{ border-bottom-color: var(--bs-border-color) !important; }
[data-bs-theme="dark"] .border-top{ border-top-color: var(--bs-border-color) !important; }
[data-bs-theme="dark"] .card-header{
  background-color: var(--bs-gray-800); /* darker than card for separation */
  color: var(--bs-body-color); /* light text in dark theme */
  border-bottom-color: var(--bs-card-border-color);
}
/* Ensure card headers have proper contrast on soft theme */
[data-bs-theme="soft"] .card-header{
  background-color: #fff3e0; /* slightly deeper than card bg */
  color: #2b2b2b;
  border-bottom-color: var(--bs-card-border-color);
}
/* Ensure form-check inputs are visible across themes */
[data-bs-theme="light"] .form-check-input{
  background-color: #ffffff;
  border-color: #343a40 !important; /* darker border for contrast */
  border-width: 2px;
}
[data-bs-theme="light"] .form-check-input:not(:checked){
  background-color: #e9ecef !important; /* light gray fill when unchecked */
}
[data-bs-theme="light"] .form-check-input:checked{
  background-color: #0d6efd;
  border-color: #0d6efd;
}
[data-bs-theme="light"] .form-check-input:hover{
  border-color: #212529 !important;
}
[data-bs-theme] .form-check-input{
  border-color: var(--bs-border-color);
}
[data-bs-theme="soft"] .form-check-input{
  background-color: #ffffff;
  border-color: #8a7f6d !important; /* deeper beige/gray for contrast */
  border-width: 2px;
}
[data-bs-theme="soft"] .form-check-input:not(:checked){
  background-color: #ece6db !important; /* soft beige-gray fill when unchecked */
}
[data-bs-theme="soft"] .form-check-input:checked{
  background-color: var(--bs-link-color, #0d6efd);
  border-color: var(--bs-link-color, #0d6efd);
}
[data-bs-theme="soft"] .form-check-input:hover{
  border-color: #6c5f4e !important;
}
[data-bs-theme="dark"] .form-check-input{
  background-color: #2a2a2a;
  border-color: #6c757d;
}
[data-bs-theme="dark"] .form-check-input:checked{
  background-color: var(--bs-link-color, #0d6efd);
  border-color: var(--bs-link-color, #0d6efd);
}
[data-bs-theme] .form-check-input:focus{
  box-shadow: 0 0 0 .25rem rgba(13,110,253,.25);
}

/* Global visibility improvements for radios/checkboxes (all themes) */
.form-check-input{
  width: 1.1em;
  height: 1.1em;
  border-width: 2px;
}
.form-check-input:not(:checked){
  background-color: #ffffff;
}
.form-check-input[type="radio"]{
  border-radius: 50%;
}
.form-check-input[type="checkbox"]{
  border-radius: .25rem;
}
</style>

<header class="border-bottom bg-body">
  <nav class="navbar navbar-expand-lg container py-3">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <span class="brand-badge rounded-circle d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;">
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
            <li><button class="dropdown-item" type="button" data-theme-value="soft"><i class="bi bi-sun me-2"></i>Мека (бежова)</button></li>
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
