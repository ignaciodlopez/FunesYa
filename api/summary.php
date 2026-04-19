<?php
declare(strict_types=1);

/**
 * api/summary.php
 * Genera (o devuelve desde caché) el resumen de un artículo usando Gemini.
 * Llamado de forma asíncrona desde article.php para evitar bloquear el TTFB.
 */

ini_set('max_execution_time', '60');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/ArticleSummarizer.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === null || $id === false || $id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db  = new Database();
$row = $db->getNewsById($id);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Artículo no encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Forzar llamada a Gemini aunque exista un snippet RSS en description.
// Preferir rss_snippet (original del feed, nunca sobreescrito) sobre description.
$originalSnippet             = $row['rss_snippet'] ?? $row['description'] ?? null;
$rowForGemini                = $row;
$rowForGemini['description'] = null;

$summarizer = new ArticleSummarizer($db);
$summary    = $summarizer->getSummary($rowForGemini);

// Fallback: si el scraping falló pero hay un snippet del RSS, usarlo como texto de entrada.
// Esto cubre servidores que rechazan el scraping pero sí publican resúmenes en el feed.
if ($summary === null && $originalSnippet !== null) {
    $snippet = trim(rtrim(trim($originalSnippet), '.'));
    if (mb_strlen($snippet, 'UTF-8') > 80) {
        $summary = $summarizer->generateFromText($snippet, (int)$row['id']);
    }
}

if ($summary === null || trim((string)$summary) === '') {
    // No cachear respuestas vacías para que clientes reintenten pronto.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
} else {
    // Un resumen válido se puede cachear brevemente para reducir carga.
    header('Cache-Control: public, max-age=300, stale-while-revalidate=600');
}

echo json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE);
