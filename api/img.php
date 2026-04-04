<?php
declare(strict_types=1);

/**
 * Proxy de imágenes.
 * Descarga imágenes externas sin cabecera Referer para saltear hotlink protection.
 * Solo se permiten dominios conocidos de medios locales para evitar que
 * se use como proxy abierto.
 */

// Dominios de imágenes permitidos (exacto o subdominio)
const ALLOWED_IMAGE_DOMAINS = [
    'estacionline.com',
    'lavozdefunes.com.ar',
    'infofunes.com.ar',
    'flex-app.tadevel-cdn.com',
    'fmdiezfunes.com.ar',
    'eloccidental.com.ar',
    'funeshoy.com.ar',
    'resizer.glanacion.com',
    'assets.dev-filo.dift.io',
    'i0.wp.com',
    'picsum.photos',
    'images.unsplash.com',
];

// Tamaño máximo de imagen a proxiar: 8 MB
const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

$rawUrl = $_GET['url'] ?? '';

if ($rawUrl === '' || !filter_var($rawUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit;
}

$parsed = parse_url($rawUrl);
$scheme = strtolower($parsed['scheme'] ?? '');
$host   = strtolower($parsed['host']   ?? '');

if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit;
}

// Verificar que el host sea un dominio permitido (exacto o subdominio)
$allowed = false;
foreach (ALLOWED_IMAGE_DOMAINS as $domain) {
    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    exit;
}

// Descargar imagen sin Referer (clave para evitar el hotlink block)
$ctx = stream_context_create([
    'http' => [
        'timeout'         => 10,
        'user_agent'      => 'FunesNewsAgent/1.0',
        'follow_location' => 1,
        'max_redirects'   => 3,
    ],
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
]);

$image = @file_get_contents($rawUrl, false, $ctx);
if ($image === false || strlen($image) === 0) {
    http_response_code(502);
    exit;
}

if (strlen($image) > MAX_IMAGE_BYTES) {
    http_response_code(413);
    exit;
}

// Extraer Content-Type de la respuesta
$contentType = 'image/jpeg';
if (!empty($http_response_header)) {
    foreach ($http_response_header as $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            $contentType = trim(substr($header, 13));
            // Quitar parámetros extra (ej: "image/jpeg; charset=...")
            [$contentType] = explode(';', $contentType);
            $contentType = trim($contentType);
            break;
        }
    }
}

// Solo servir imágenes
if (!str_starts_with($contentType, 'image/')) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=86400'); // 24 h de caché en el browser
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($image));
echo $image;
