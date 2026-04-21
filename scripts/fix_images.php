<?php
declare(strict_types=1);

/**
 * Script unificado de reparación de imágenes.
 * Realiza una limpieza lógica (SQL) seguida de una reparación física (HTTP).
 *
 * Uso: php scripts/fix_images.php [limite_reparacion]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Aggregator.php';

$db = new Database();
$pdo = new ReflectionProperty(Database::class, 'pdo');
$pdo->setAccessible(true);
/** @var PDO $dbPdo */
$dbPdo = $pdo->getValue($db);

$limit = isset($argv[1]) ? (int)$argv[1] : 200;

echo "=== INICIO DE MANTENIMIENTO DE IMÁGENES ===\n\n";

// ---------------------------------------------------------
// FASE 1: Limpieza Rápida (SQL)
// ---------------------------------------------------------
echo "[FASE 1] Ejecutando limpieza lógica...\n";

// 1.1. Resetear placeholders de Estación Online
$sqlEstacion = "UPDATE news 
                SET image_url = NULL 
                WHERE source = 'Estacionline' 
                AND (
                    image_url LIKE '%social-image-generator%' 
                    OR image_url LIKE '%sig-image%' 
                    OR image_url LIKE '%?sig=%'
                )";
$stmtEstacion = $dbPdo->prepare($sqlEstacion);
$stmtEstacion->execute();
echo "- Estación Online: Se resetearon " . $stmtEstacion->rowCount() . " imágenes de plantilla.\n";

// 1.2. Resetear imágenes "falsamente legítimas" de Estación Online (slug.jpg sin sufijos)
// Buscamos artículos de Estacionline cuyas imágenes no tengan guiones seguidos de números ni palabras clave de escalado.
// NOTA: Como SQLite no soporta regex complejas en UPDATE directo fácilmente, lo hacemos por ID.
$stmtEstacionAdv = $dbPdo->query("
    SELECT id, image_url 
    FROM news 
    WHERE source = 'Estacionline' 
      AND image_url IS NOT NULL
");
$toReset = [];
while ($row = $stmtEstacionAdv->fetch(PDO::FETCH_ASSOC)) {
    $filename = basename(parse_url($row['image_url'], PHP_URL_PATH) ?? '');
    // 1. Si es de Estacionline y parece la imagen generada por texto (slug sin sufijos)
    $isEstacionPlaceholder = (preg_match('/^[a-z0-9-]+\.(jpe?g|png|webp|avif)$/i', $filename) && !preg_match('/-\d+|scaled/i', $filename));
    
    // 2. Si contiene palabras clave de imágenes genéricas (logo, favicon, etc.)
    $isGeneric = false;
    $genericPatterns = ['logo', 'favicon', 'favicom', 'avatar', 'placeholder', 'icon', 'brand', 'nav-logo'];
    foreach ($genericPatterns as $p) {
        if (str_contains(strtolower($row['image_url']), $p)) {
            $isGeneric = true;
            break;
        }
    }

    if ($isEstacionPlaceholder || $isGeneric) {
        $toReset[] = $row['id'];
    }
}
if (!empty($toReset)) {
    $dbPdo->exec("UPDATE news SET image_url = NULL WHERE id IN (" . implode(',', $toReset) . ")");
}
echo "- Estación Online (Avanzado): Se resetearon " . count($toReset) . " imágenes genéricas o de plantilla.\n";

// 1.3. Resetear imágenes de stock (Picsum/Unsplash)
$sqlStock = "UPDATE news 
             SET image_url = NULL 
             WHERE image_url LIKE 'https://picsum.photos/%' 
                OR image_url LIKE 'https://images.unsplash.com/%'";
$stmtStock = $dbPdo->prepare($sqlStock);
$stmtStock->execute();
echo "- Imágenes stock: Se resetearon " . $stmtStock->rowCount() . " imágenes.\n";

// 1.3. Corregir URLs malformadas (Concatenación errónea o dobles slashes)
$rows = $dbPdo->query("SELECT id, image_url FROM news WHERE image_url IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$updateUrlStmt = $dbPdo->prepare("UPDATE news SET image_url = :img WHERE id = :id");
$fixedUrls = 0;

foreach ($rows as $row) {
    $url = $row['image_url'];
    $cleaned = $url;

    // Patrón: https://host/https://...
    if (preg_match('#^https?://[^/]+/(https?://.+)$#', $url, $m)) {
        $cleaned = $m[1];
    }
    // Patrón: https://host//path
    $cleaned = preg_replace('#^(https?://[^/]+)//(.+)$#', '$1/$2', $cleaned);

    if ($cleaned !== $url) {
        $updateUrlStmt->execute([':img' => $cleaned, ':id' => $row['id']]);
        $fixedUrls++;
    }
}
echo "- URLs malformadas: Se corrigieron $fixedUrls registros.\n";


// ---------------------------------------------------------
// FASE 2: Reparación Masiva (HTTP)
// ---------------------------------------------------------
echo "\n[FASE 2] Iniciando reparación física (límite: $limit)...\n";

$articles = $db->getRecentArticlesWithoutImage($limit);

if (empty($articles)) {
    echo "No hay artículos que requieran reparación física.\n";
} else {
    echo "Procesando " . count($articles) . " artículos...\n";
    
    $agg = new Aggregator($db);
    $method = new ReflectionMethod(Aggregator::class, 'fetchOgImage');
    $method->setAccessible(true);
    
    $found = 0;
    foreach ($articles as $index => $article) {
        $num = $index + 1;
        echo "[$num/" . count($articles) . "] ID {$article['id']}: {$article['link']}... ";
        
        $image = $method->invoke($agg, $article['link']);

        if ($image && !str_contains($image, '.gif')) {
            $db->updateImageUrl((int)$article['id'], $image);
            echo "¡ÉXITO! -> $image\n";
            $found++;
        } else {
            echo "falló.\n";
        }
        
        // Pausa breve para cortesía con los servidores
        usleep(150_000); 
    }
    echo "\nReparación finalizada. Imágenes nuevas recuperadas: $found\n";
}

echo "\n=== MANTENIMIENTO COMPLETO ===\n";
