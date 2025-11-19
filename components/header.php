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
    document.documentElement.setAttribute('data-bs-theme', val);
    try{ localStorage.setItem('tg-theme', val);}catch(e){}
  }
  document.querySelectorAll('[data-theme-value]').forEach(function(btn){
    btn.addEventListener('click', function(){ setTheme(btn.getAttribute('data-theme-value')); });
  });
})();
</script>
<script src="backToTop.js" defer></script>
