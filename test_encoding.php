<?php
ob_start();
include 'index.php';
$html = ob_get_clean();

$required = [
    'Интерактивни тестове • Статистика • Напредък',
    'Учи и тествай уменията си по интелигентен начин',
    'Платформа за бързи и адаптивни тестове',
    'Започни бърз тест',
    'Разгледай тестовете',
    'Търси тест',
    'Категории',
    'Последни тестове',
    'Как работи?'
];

$missing = [];
foreach ($required as $text) {
    if (strpos($html, $text) === false) {
        $missing[] = $text;
    }
}

if (count($missing) > 0) {
    echo "FAIL. Missing strings:\n";
    foreach ($missing as $m) {
        echo "- $m\n";
    }
    exit(1);
}

echo "PASS. All strings found.\n";
exit(0);
