<?php
/**
 * Router para el servidor de desarrollo built-in de PHP.
 * Úsalo con: php -S 127.0.0.1:9000 router.php
 *
 * El document root del servidor es la raíz del proyecto, pero los archivos
 * web viven en public/. El router los resuelve manualmente porque `return false`
 * solo funciona cuando el archivo está en el document root del servidor.
 */

$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// /articulo/ID-slug → public/article.php?id=ID
if (preg_match('#^/articulo/(\d+)#', $path, $m)) {
    $_GET['id'] = (int)$m[1];
    require __DIR__ . '/public/article.php';
    return true;
}

$file = __DIR__ . '/public' . $path;

if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    // Archivos PHP de public/ (api/*.php, sitemap.php, etc.)
    if (str_ends_with($file, '.php')) {
        require $file;
        return true;
    }

    // Archivos estáticos: servir manualmente desde public/
    // (return false no funciona porque el doc root del servidor es la raíz, no public/)
    static $mime = [
        'css'   => 'text/css',
        'js'    => 'application/javascript; charset=utf-8',
        'woff2' => 'font/woff2',
        'woff'  => 'font/woff',
        'ttf'   => 'font/ttf',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'ico'   => 'image/x-icon',
        'webp'  => 'image/webp',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
        'json'  => 'application/json',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

// Cualquier otra ruta → public/index.php
require __DIR__ . '/public/index.php';
return true;
