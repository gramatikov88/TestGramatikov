<?php
// expects $testTheme (string) and optional $testThemeConfig (array or json string)
if (!isset($testTheme)) { $testTheme = 'default'; }
if (isset($testThemeConfig) && is_string($testThemeConfig)) {
    $dec = json_decode($testThemeConfig, true);
    if (json_last_error() === JSON_ERROR_NONE) { $testThemeConfig = $dec; }
}
if (!is_array($testThemeConfig ?? null)) { $testThemeConfig = []; }

// Default palettes
$palettes = [
    'default' => [ 'primary' => '#0d6efd', 'bg' => 'transparent', 'card' => '#ffffff', 'header' => '#0d6efd22', 'text' => '#212529', 'button' => '#0d6efd', 'border' => 'rgba(0,0,0,.08)' ],
    'soft'    => [ 'primary' => '#0d6efd', 'bg' => '#fff7ec', 'card' => '#ffffff', 'header' => '#f0d9b5', 'text' => '#2b2b2b', 'button' => '#0d6efd', 'border' => 'rgba(0,0,0,.06)' ],
    'dark'    => [ 'primary' => '#0d6efd', 'bg' => '#1e1e1e', 'card' => '#2a2a2a', 'header' => '#121212', 'text' => '#e9ecef', 'button' => '#0d6efd', 'border' => 'rgba(255,255,255,.15)' ],
    'ocean'   => [ 'primary' => '#0aa2c0', 'bg' => '#e6f7fb', 'card' => '#ffffff', 'header' => '#8ad5e3', 'text' => '#14313f', 'button' => '#0aa2c0', 'border' => 'rgba(0,0,0,.06)' ],
    'orange'  => [ 'primary' => '#f57c00', 'bg' => '#fff3e0', 'card' => '#ffffff', 'header' => '#ffd8a6', 'text' => '#3a2a12', 'button' => '#f57c00', 'border' => 'rgba(0,0,0,.06)' ],
    'forest'  => [ 'primary' => '#198754', 'bg' => '#eef7f0', 'card' => '#ffffff', 'header' => '#c9ead6', 'text' => '#0f2e22', 'button' => '#198754', 'border' => 'rgba(0,0,0,.06)' ],
    'berry'   => [ 'primary' => '#b23c9a', 'bg' => '#fbe7f6', 'card' => '#ffffff', 'header' => '#f3c5e9', 'text' => '#37152f', 'button' => '#b23c9a', 'border' => 'rgba(0,0,0,.06)' ],
    'custom'  => [ 'primary' => '#0d6efd', 'bg' => '#ffffff', 'card' => '#ffffff', 'header' => '#e9ecef', 'text' => '#212529', 'button' => '#0d6efd', 'border' => 'rgba(0,0,0,.08)' ],
];

$base = $palettes[$testTheme] ?? $palettes['default'];
foreach ($testThemeConfig as $k => $v) {
    if (isset($base[$k]) && is_string($v) && $v !== '') { $base[$k] = $v; }
}
?>
<style>
.tg-test{ --tg-primary: <?= htmlspecialchars($base['primary']) ?>; --tg-bg: <?= htmlspecialchars($base['bg']) ?>; --tg-card: <?= htmlspecialchars($base['card']) ?>; --tg-header: <?= htmlspecialchars($base['header']) ?>; --tg-text: <?= htmlspecialchars($base['text']) ?>; --tg-button: <?= htmlspecialchars($base['button']) ?>; --tg-border: <?= htmlspecialchars($base['border']) ?>; }
.tg-test .tg-form{ max-width: 760px; margin: 0 auto; }
.tg-test .tg-header{ background: linear-gradient(180deg, var(--tg-header), rgba(0,0,0,0.02)); border-radius: .75rem; padding: 28px 24px; margin-bottom: 16px; }
.tg-test .tg-header .title{ font-size: 1.75rem; font-weight: 700; color: var(--tg-text); }
.tg-test .tg-card{ background: var(--tg-card); border: 1px solid var(--tg-border); border-radius: .75rem; box-shadow: 0 6px 18px rgba(0,0,0,.05); margin-bottom: 12px; }
.tg-test .tg-card .tg-body{ padding: 16px 18px; }
.tg-test .q-title{ font-weight: 600; color: var(--tg-text); }
.tg-test .q-points{ background: rgba(0,0,0,.05); color: var(--tg-text); }
.tg-test .q-accent{ border-left: 4px solid var(--tg-primary); }
.tg-test input[type=radio], .tg-test input[type=checkbox]{ accent-color: var(--tg-primary); }
.tg-test .btn-primary{ background-color: var(--tg-button); border-color: var(--tg-button); }
.tg-test .btn-primary:hover{ filter: brightness(0.95); }
.tg-test .submit-wrap{ text-align: right; }

/* Map Bootstrap variables within test execution only */
body.tg-theme-active{
  --bs-body-bg: var(--tg-bg);
  --bs-body-color: var(--tg-text);
  --bs-card-bg: var(--tg-card);
  --bs-card-color: var(--tg-text);
  --bs-card-border-color: var(--tg-border);
  --bs-border-color: var(--tg-border);
  --bs-link-color: var(--tg-primary);
  --bs-link-hover-color: var(--tg-primary);
  background-color: var(--tg-bg) !important;
  color: var(--tg-text) !important;
}
body.tg-theme-active .card{ background: var(--tg-card); border-color: var(--tg-border); color: var(--tg-text); }
body.tg-theme-active .card-header{ background: var(--tg-header); color: var(--tg-text); border-bottom-color: var(--tg-border); }
body.tg-theme-active .form-control, body.tg-theme-active .form-select{ background-color: #fff; border-color: var(--tg-border); color: var(--tg-text); }
body.tg-theme-active .badge{ background: rgba(0,0,0,.05); color: var(--tg-text); }
</style>
<script>
// Limit theme variables to test execution pages only
document.addEventListener('DOMContentLoaded', function(){
  document.body.classList.add('tg-theme-active');
});
</script>