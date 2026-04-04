<?php
declare(strict_types=1);

/**
 * Repara URLs de imagen rotas en la base de datos.
 * Patrones conocidos:
 *   - "https://host/https://..."   → strip "https://host/"
 *   - "https://host//path"         → strip doble slash
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/news.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $pdo->query("SELECT id, image_url FROM news WHERE image_url IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("UPDATE news SET image_url = :img WHERE id = :id");
$fixed = 0;

foreach ($rows as $row) {
    $url     = $row['image_url'];
    $cleaned = $url;

    // Patrón: https://somehost/https://real-cdn.com/...
    // Producido por concatenar scheme://host + /https://...
    if (preg_match('#^https?://[^/]+/(https?://.+)$#', $url, $m)) {
        $cleaned = $m[1];
    }

    // Patrón: https://somehost//path (doble slash)
    $cleaned = preg_replace('#^(https?://[^/]+)//(.+)$#', '$1/$2', $cleaned);

    if ($cleaned !== $url) {
        $stmt->execute([':img' => $cleaned, ':id' => $row['id']]);
        echo "ID {$row['id']}: {$url}\n        → {$cleaned}\n";
        $fixed++;
    }
}

echo "\nTotal reparados: {$fixed}\n";
