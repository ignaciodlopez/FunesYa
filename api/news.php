<?php
declare(strict_types=1);

/**
 * Endpoint REST que devuelve noticias locales en formato JSON.
 * Lanza el aggregator en background para no bloquear la respuesta al usuario.
 * Soporta ETag/304 para reducir tráfico en las peticiones de polling.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../src/Database.php';

try {
    $db = new Database();

    $lastUpdate    = $db->getLastUpdate();
    $minutesPassed = (time() - $lastUpdate) / 60;

    // Lanzar actualización en background cada 2 minutos.
    // Se marca el timestamp ANTES de lanzar para que requests simultáneos
    // no disparen múltiples procesos.
    if ($minutesPassed >= 2) {
        $db->setLastUpdate(time());
        $lastUpdate = $db->getLastUpdate(); // Actualizar valor local tras el cambio
        $script = realpath(__DIR__ . '/../scripts/run_aggregator.php');
        if ($script !== false) {
            // Windows: start /b lanza el proceso sin bloquear
            $php = PHP_BINARY;
            pclose(popen("cmd /c start /b \"\" \"{$php}\" \"{$script}\" > NUL 2>&1", 'r'));
        }
    }

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
    http_response_code(500);
    // No exponer detalles internos (rutas, mensajes de PDO, etc.)
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error interno del servidor. Por favor, intente nuevamente.',
    ]);
}
