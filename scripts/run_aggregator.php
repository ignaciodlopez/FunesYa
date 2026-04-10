<?php
declare(strict_types=1);

/**
 * Script de actualización de noticias que se ejecuta en background.
 * Se invoca desde api/news.php de forma asíncrona para no bloquear al usuario.
 *
 * Usa flock() para garantizar exclusión mutua atómica y evitar
 * la condición de carrera (TOCTOU) que existiría con file_exists().
 */

// Solo puede ejecutarse desde CLI o desde popen() del servidor
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$lockFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'funesya-aggregator.lock';

// Abrir (o crear) el archivo de lock
$lock = fopen($lockFile, 'c');
if ($lock === false) {
    exit;
}

// Intentar adquirir el lock en modo no bloqueante.
// Si otro proceso ya lo tiene, salir sin hacer nada.
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    exit;
}

try {
    require_once __DIR__ . '/../src/Database.php';
    require_once __DIR__ . '/../src/Aggregator.php';

    $db  = new Database();
    $agg = new Aggregator($db);
    $agg->fetchAll();
} finally {
    // Liberar el lock y cerrar antes de eliminar el archivo
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($lockFile);
}
