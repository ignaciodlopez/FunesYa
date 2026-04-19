<?php
declare(strict_types=1);

/**
 * Endpoint REST que devuelve noticias locales en formato JSON.
 * La actualización de datos la maneja el cron job (cada 2 min).
 * Soporta ETag/304 para reducir tráfico en las peticiones de polling.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../../src/Database.php';

try {
    $db = new Database();

    $lastUpdate = $db->getLastUpdate();

    // ETag basado en last_update: si el cliente ya tiene la versión actual,
    // responder 304 sin cuerpo (ahorra todo el JSON en la mayoría de polls).
    $etag = '"' . $lastUpdate . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: no-cache'); // Siempre revalidar, pero permitir caché local

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
        http_response_code(304);
        exit;
    }

    // Sanitizar y validar parámetros de entrada
    $rawSource = isset($_GET['source']) ? trim((string)$_GET['source']) : null;
    $source    = ($rawSource !== null && mb_strlen($rawSource) <= 100) ? $rawSource : null;

    $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit  = 12;
    $offset = ($page - 1) * $limit;

    $news    = $db->getNews($limit, $source, $offset);
    $sources = $db->getSources();

    // Construye la lista de fuentes asegurando que "Todas" sea la primera opción
    $allSources = ['Todas'];
    foreach ($sources as $s) {
        if ($s !== 'Todas') $allSources[] = $s;
    }

    echo json_encode([
        'status'      => 'success',
        'last_update' => date('Y-m-d H:i:s', $lastUpdate),
        'sources'     => $allSources,
        'page'        => $page,
        'has_more'    => count($news) === $limit,
        'data'        => $news,
    ], JSON_THROW_ON_ERROR);

} catch (Exception $e) {
    error_log('[FunesYa][api/news.php] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    // No exponer detalles internos (rutas, mensajes de PDO, etc.)
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error interno del servidor. Por favor, intente nuevamente.',
    ]);
}
