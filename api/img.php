<?php
declare(strict_types=1);

/**
 * Proxy de imágenes con caché de disco.
 * Descarga imágenes externas sin cabecera Referer para saltear hotlink protection.
 * Las imágenes se almacenan en data/img_cache/ por 24 h, evitando re-descargas
 * repetidas del origen en cada request.
 * Solo se permiten dominios conocidos de medios locales para evitar que
 * se use como proxy abierto.
 */

// Dominios de imágenes permitidos (exacto o subdominio)
const ALLOWED_IMAGE_DOMAINS = [
    'estacionline.com',
    'lavozdefunes.com.ar',
    'infofunes.com.ar',
    'flex-assets.tadevel-cdn.com',
    'fmdiezfunes.com.ar',
    'eloccidental.com.ar',
    'funeshoy.com.ar',
    'resizer.glanacion.com',
    'assets.dev-filo.dift.io',
    'radiofonica.com',
    'ambito.com',
    'media.ambito.com',
    'elliberador.com',
    'i0.wp.com',
    'infobae.com',
    'tn.com.ar',
    'resizer.lavoz.com.ar',
    'cloudfront.net',
];

// Tamaño máximo de imagen a proxiar: 8 MB
const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

// TTL del caché de disco según tamaño:
// imágenes pequeñas (<50 KB, probables placeholders) → 1 hora
// imágenes grandes (fotos reales)                   → 24 horas
const CACHE_TTL_SMALL = 3600;
const CACHE_TTL_LARGE = 86400;
const CACHE_SMALL_THRESHOLD = 50 * 1024; // 50 KB

// Directorio de caché (dentro de data/ que ya está protegido por .htaccess)
$cacheDir = __DIR__ . '/../data/img_cache';

// Limpieza proactiva: borrar archivos expirados una vez cada ~200 requests
// (probabilidad 0.5 % para no impactar latencia en el caso habitual)
if (is_dir($cacheDir) && mt_rand(1, 200) === 1) {
    $maxTtl = CACHE_TTL_LARGE;
    foreach (glob($cacheDir . '/*') as $f) {
        if (is_file($f) && (time() - filemtime($f)) > $maxTtl) {
            @unlink($f);
        }
    }
}

$rawUrl = $_GET['url'] ?? '';

// Ancho máximo de salida (0 = sin redimensionar). Validado a [1, 2000].
$maxWidth = isset($_GET['w']) ? max(0, min(2000, (int)$_GET['w'])) : 0;

// Decodificar HTML entities (&amp; → &) que pueden venir de URLs mal almacenadas
$rawUrl = html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// PHP decodifica los parámetros GET, por lo que la URL puede contener caracteres
// no-ASCII en crudo (ej: U+200E LRM en el path). filter_var los rechaza, pero
// file_get_contents los necesita percent-encoded para localizar el archivo remoto.
// Solución: re-codificar cada byte no-ASCII como %XX.
$rawUrl = preg_replace_callback('/[^\x00-\x7F]+/', fn($m) => rawurlencode($m[0]), $rawUrl);

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

// ── Caché de disco ─────────────────────────────────────────────────────────────────────────
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}

$cacheHash  = sha1($rawUrl . ($maxWidth > 0 ? ':w' . $maxWidth : ''));
$cachedFiles = glob($cacheDir . '/' . $cacheHash . '.*');
$cachedFile  = $cachedFiles[0] ?? null;

if ($cachedFile) {
    $cachedSize = filesize($cachedFile);
    $ttl = ($cachedSize !== false && $cachedSize < CACHE_SMALL_THRESHOLD)
        ? CACHE_TTL_SMALL
        : CACHE_TTL_LARGE;

    if ((time() - filemtime($cachedFile)) < $ttl) {
        // Servir desde caché: 0 requests HTTP externos
        $ext = pathinfo($cachedFile, PATHINFO_EXTENSION);
        $contentType = match ($ext) {
            'jpg'  => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            default => 'image/jpeg',
        };
        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=' . $ttl);
        header('X-Content-Type-Options: nosniff');
        header('X-Cache: HIT');
        readfile($cachedFile);
        exit;
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// Descargar imagen simulando un navegador para evitar bloqueos 403
$ctx = stream_context_create([
    'http' => [
        'timeout'         => 10,
        'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'follow_location' => 1,
        'max_redirects'   => 5,
        'header'          => implode("\r\n", [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language: es-AR,es;q=0.9',
            'Accept-Encoding: identity',
            'Cache-Control: no-cache',
        ]),
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

/**
 * Redimensiona la imagen al ancho indicado y la convierte a WebP si GD lo soporta.
 * Devuelve los bytes procesados o null si no se puede transformar.
 * El tipo MIME de salida se escribe en $contentType por referencia.
 */
function resizeAndConvert(string $imageData, string &$contentType, int $maxWidth): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }

    // SVG y GIF animado: no se procesan con GD
    if (in_array($contentType, ['image/svg+xml', 'image/gif'], true)) {
        return null;
    }

    $src = @imagecreatefromstring($imageData);
    if ($src === false) {
        return null;
    }

    $origW = imagesx($src);
    $origH = imagesy($src);

    if ($maxWidth > 0 && $origW > $maxWidth) {
        $newW = $maxWidth;
        $newH = (int)round($origH * ($maxWidth / $origW));
        $dst  = imagecreatetruecolor($newW, $newH);
        // Preservar transparencia (PNG)
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);
        $src = $dst;
    }

    // Intentar WebP (GD >= 7.0 lo soporta en la mayoría de instalaciones)
    if (function_exists('imagewebp')) {
        ob_start();
        $ok = imagewebp($src, null, 82);
        $result = ob_get_clean();
        imagedestroy($src);
        if ($ok && $result !== false && strlen($result) > 100) {
            $contentType = 'image/webp';
            return $result;
        }
    } else {
        imagedestroy($src);
    }

    return null; // No se pudo convertir: se sirve el original
}

// Redimensionar y/o convertir a WebP siempre que GD lo permita
// (no solo cuando se pidió ancho, así el caché acumula WebP en lugar de JPEG/PNG)
$needsProcessing = !in_array($contentType, ['image/svg+xml', 'image/gif'], true);
if ($needsProcessing) {
    $processed = resizeAndConvert($image, $contentType, $maxWidth);
    if ($processed !== null) {
        $image = $processed;
    }
}

// Guardar en caché de disco para próximos requests
$ext = match ($contentType) {
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/gif'     => 'gif',
    'image/webp'    => 'webp',
    'image/svg+xml' => 'svg',
    default         => 'img',
};
// Eliminar caché stale previo si existía con distinta extensión
if ($cachedFile && file_exists($cachedFile)) {
    unlink($cachedFile);
}
$cachedSize = strlen($image);
$ttl = $cachedSize < CACHE_SMALL_THRESHOLD ? CACHE_TTL_SMALL : CACHE_TTL_LARGE;
file_put_contents($cacheDir . '/' . $cacheHash . '.' . $ext, $image);

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=' . $ttl);
header('X-Content-Type-Options: nosniff');
header('X-Cache: MISS');
header('Content-Length: ' . strlen($image));
echo $image;
