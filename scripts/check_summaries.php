<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/news.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $pdo->query("SELECT id, title, source, link, description FROM news ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $isSnippet  = $r['description'] && str_ends_with(rtrim($r['description']), '...');
    $isMock     = str_starts_with($r['link'], 'https://example.com');
    $descStatus = $r['description'] === null ? 'NULL' : ($isSnippet ? 'SNIPPET(...)' : 'OK');

    echo "ID: {$r['id']} [{$r['source']}] [{$descStatus}]" . PHP_EOL;
    echo "TITLE: " . mb_substr($r['title'], 0, 70) . PHP_EOL;
    echo "LINK: " . $r['link'] . PHP_EOL;
    if ($r['description']) {
        echo "DESC: " . mb_substr($r['description'], 0, 200) . PHP_EOL;
    }
    echo PHP_EOL;
}
