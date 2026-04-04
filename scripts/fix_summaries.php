<?php
declare(strict_types=1);

/**
 * Script de reparación de resúmenes.
 * Busca artículos cuya descripción es un snippet truncado del RSS (termina en "...")
 * y los reemplaza con un resumen generado por Gemini AI.
 *
 * Uso: php scripts/fix_summaries.php
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/ArticleSummarizer.php';

$db   = new Database();
$pdo  = (fn() => (function () use ($db) {
    // Acceso directo a PDO via reflexión para no exponer el objeto
    $r = new ReflectionProperty(Database::class, 'pdo');
    $r->setAccessible(true);
    return $r->getValue($db);
})())();

// Buscar artículos con snippet truncado o sin descripción, excluyendo mocks
$stmt = $pdo->query("
    SELECT id, title, link, description
    FROM news
    WHERE link NOT LIKE 'https://example.com%'
      AND (
          description IS NULL
          OR description LIKE '%...'
      )
    ORDER BY pub_date DESC
");

$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total    = count($articles);

if ($total === 0) {
    echo "No hay artículos que necesiten resumen.\n";
    exit;
}

echo "Artículos a procesar: {$total}\n\n";

$summarizer = new ArticleSummarizer($db);
$ok = 0;
$fail = 0;

foreach ($articles as $i => $article) {
    $num = $i + 1;
    $title = mb_substr($article['title'], 0, 60);
    echo "[{$num}/{$total}] {$title}...\n";

    // Forzar regeneración limpiando la descripción existente (snippet del RSS)
    $summary = $summarizer->getSummary(array_merge($article, ['description' => null]));

    if ($summary && !str_ends_with(rtrim($summary), '...')) {
        echo "  ✓ Resumen generado\n";
        $ok++;
    } else {
        echo "  ✗ Falló (se dejó el fallback o el snippet original)\n";
        $fail++;
    }

    // Pausa breve para no saturar la API de Gemini
    if ($num < $total) {
        sleep(1);
    }
}

echo "\nListo. Correctos: {$ok} — Fallidos: {$fail}\n";
