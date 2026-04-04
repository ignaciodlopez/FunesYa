<?php
$pdo = new PDO('sqlite:' . __DIR__ . '/../data/news.sqlite');
$count = $pdo->query("SELECT COUNT(id) FROM news WHERE link LIKE 'https://example.com%'")->fetchColumn();
echo "Mocks a eliminar: {$count}\n";
$pdo->exec("DELETE FROM news WHERE link LIKE 'https://example.com%'");
echo "Eliminados.\n";
