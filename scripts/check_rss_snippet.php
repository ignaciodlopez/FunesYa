<?php
$db = new PDO('sqlite:/var/www/html/data/news.sqlite');
$cols = array_column($db->query('PRAGMA table_info(news)')->fetchAll(PDO::FETCH_ASSOC), 'name');
echo implode(', ', $cols) . PHP_EOL;
$rows = $db->query("SELECT id, LENGTH(rss_snippet) as snip_len, LENGTH(description) as desc_len FROM news WHERE source='Estacionline' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo 'ID:' . $r['id'] . ' rss_snippet:' . $r['snip_len'] . ' desc:' . $r['desc_len'] . PHP_EOL;
}
