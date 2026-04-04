<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/news.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Extrae la clave canónica de un link para deduplicación.
 * Para InfoFunes, el ID real es el hash hexadecimal al final de la URL.
 */
function canonicalKey(string $link, string $source): string {
    $normalized = rtrim(strtolower($link), '/');
    if ($source === 'InfoFunes' && preg_match('/_([0-9a-f]{16,})$/', $normalized, $m)) {
        return 'infofunes:' . $m[1];
    }
    return $normalized;
}

// Cargar todos los artículos
$all = $pdo->query("SELECT id, title, link, source FROM news ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$seen       = [];   // canonicalKey => id a mantener
$toDelete   = [];   // ids a eliminar

foreach ($all as $row) {
    $key = canonicalKey($row['link'], $row['source']);
    if (isset($seen[$key])) {
        $toDelete[] = $row['id'];
        echo "DUP id={$row['id']} (keep id={$seen[$key]}): " . mb_substr($row['title'], 0, 60) . PHP_EOL;
        echo "  " . $row['link'] . PHP_EOL;
    } else {
        $seen[$key] = $row['id'];
    }
}

if (empty($toDelete)) {
    echo "No hay duplicados canónicos. BD limpia." . PHP_EOL;
    exit(0);
}

echo PHP_EOL . count($toDelete) . " duplicados encontrados. Eliminando..." . PHP_EOL;

$stmt = $pdo->prepare("DELETE FROM news WHERE id = :id");
foreach ($toDelete as $id) {
    $stmt->execute([':id' => $id]);
}

echo "Listo. Se eliminaron " . count($toDelete) . " registros duplicados." . PHP_EOL;
