<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

$source = $argv[1] ?? 'Estacionline';

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/news.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "
    UPDATE news
    SET description = NULL
    WHERE source = :source
      AND description IS NOT NULL
    AND (
        (LENGTH(TRIM(description)) BETWEEN 1 AND 119
         AND SUBSTR(TRIM(description), -1, 1) NOT IN ('.', '!', '?', '…'))
       OR (TRIM(description) LIKE '%...' AND LENGTH(TRIM(description)) < 320)
    )
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':source' => $source]);

echo "Fuente: {$source}" . PHP_EOL;
echo 'Resúmenes truncados limpiados: ' . $stmt->rowCount() . PHP_EOL;
