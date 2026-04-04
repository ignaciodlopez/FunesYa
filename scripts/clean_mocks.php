<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/news.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$count = $pdo->query("SELECT COUNT(id) FROM news WHERE link LIKE 'https://example.com%'")->fetchColumn();
echo "Mocks a eliminar: {$count}\n";
$pdo->exec("DELETE FROM news WHERE link LIKE 'https://example.com%'");
echo "Eliminados.\n";
