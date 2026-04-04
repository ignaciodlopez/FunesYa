<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/ArticleSummarizer.php';

$db = new Database();
$summarizer = new ArticleSummarizer($db);

// Obtener artículos con snippet RSS (terminan en "...") o sin descripción
$pdo  = new PDO('sqlite:' . __DIR__ . '/../data/news.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $pdo->query("
    SELECT id, title, link, description
    FROM news
    WHERE link NOT LIKE 'https://example.com%'
      AND (
          description IS NULL
          OR (description LIKE '%...' AND LENGTH(description) < 500)
      )
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo count($rows) . " artículos con descripción incompleta o faltante." . PHP_EOL . PHP_EOL;

$ok = 0;
$fail = 0;
foreach ($rows as $article) {
    // Forzar regeneración: limpiar description para que getSummary() llame a Gemini
    $article['description'] = null;
    $summary = $summarizer->getSummary($article);
    if ($summary !== null) {
        echo "[OK] ID {$article['id']}: " . mb_substr($article['title'], 0, 60) . PHP_EOL;
        $ok++;
    } else {
        echo "[FAIL] ID {$article['id']}: " . mb_substr($article['title'], 0, 60) . PHP_EOL;
        $fail++;
    }
    // Pausa para no saturar la API de Gemini
    usleep(300_000); // 300 ms
}

echo PHP_EOL . "Completado: $ok ok, $fail fallidos." . PHP_EOL;


echo "\nListo. Correctos: {$ok} — Fallidos: {$fail}\n";
