<?php
declare(strict_types=1);

/**
 * Script de reparación de imágenes.
 * Recorre todos los artículos e intenta reemplazar su image_url con el
 * og:image real del artículo original.
 * Solo actualiza el registro si se encontró una imagen real distinta a la guardada.
 *
 * Uso: php scripts/fix_images.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Aggregator.php';

$db = new Database();

// Acceder a PDO vía reflexión
$r = new ReflectionProperty(Database::class, 'pdo');
$r->setAccessible(true);
/** @var PDO $pdo */
$pdo = $r->getValue($db);

// Todos los artículos reales (sin mocks)
$stmt = $pdo->query("
    SELECT id, link, image_url
    FROM news
    WHERE link NOT LIKE 'https://example.com%'
    ORDER BY pub_date DESC
");

$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total    = count($articles);

if ($total === 0) {
    echo "No hay artículos en la base de datos.\n";
    exit;
}

echo "Artículos a procesar: {$total}\n\n";

$agg    = new Aggregator($db);
$method = new ReflectionMethod(Aggregator::class, 'fetchOgImage');
$method->setAccessible(true);

$updateStmt = $pdo->prepare("UPDATE news SET image_url = :img WHERE id = :id");

$ok        = 0;
$unchanged = 0;
$fail      = 0;

foreach ($articles as $i => $article) {
    $num = $i + 1;
    echo "[{$num}/{$total}] ID {$article['id']} — ";

    $image = $method->invoke($agg, $article['link']);

    if ($image !== null && stripos($image, '.gif') === false) {
        if ($image !== $article['image_url']) {
            $updateStmt->execute([':img' => $image, ':id' => $article['id']]);
            echo "Actualizada → {$image}\n";
            $ok++;
        } else {
            echo "Sin cambios (ya tiene og:image).\n";
            $unchanged++;
        }
    } else {
        echo "Sin og:image. Se mantiene la actual.\n";
        $fail++;
    }

    // Pausa breve para no saturar los servidores de noticias
    usleep(300_000); // 0.3 s
}

echo "\nListo. Actualizadas: {$ok} | Sin cambios: {$unchanged} | Sin imagen real: {$fail}\n";
