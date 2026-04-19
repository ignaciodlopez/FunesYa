<?php
$url = $argv[1] ?? 'https://estacionline.com/obra-cascada-saladillo-puente-molino-blanco/';
$html = @file_get_contents($url, false, stream_context_create([
    'http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
    'ssl'  => ['verify_peer' => true],
]));
if ($html === false) {
    echo "SCRAPING FALLO\n";
} else {
    echo "OK: " . strlen($html) . " bytes\n";
    echo substr(strip_tags($html), 0, 500) . "\n";
}
