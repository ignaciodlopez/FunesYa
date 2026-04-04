<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/news.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Crear columna e índice si no existen (idempotente)
try { $pdo->exec('ALTER TABLE news ADD COLUMN canonical_key TEXT'); } catch (\Exception $e) {}
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_canonical_key ON news (canonical_key) WHERE canonical_key IS NOT NULL');

// Backfill: InfoFunes con hash hex
$rows  = $pdo->query("SELECT id, link FROM news WHERE source = 'InfoFunes' AND canonical_key IS NULL")->fetchAll(PDO::FETCH_ASSOC);
$upd   = $pdo->prepare('UPDATE news SET canonical_key = :k WHERE id = :id');
$count = 0;
foreach ($rows as $r) {
    if (preg_match('/_([0-9a-f]{16,})$/', strtolower(rtrim($r['link'], '/')), $m)) {
        $upd->execute([':k' => 'infofunes:' . $m[1], ':id' => $r['id']]);
        $count++;
    }
}

// Reporte
$total   = $pdo->query("SELECT COUNT(*) FROM news WHERE source = 'InfoFunes'")->fetchColumn();
$withKey = $pdo->query("SELECT COUNT(*) FROM news WHERE source = 'InfoFunes' AND canonical_key IS NOT NULL")->fetchColumn();

echo "Backfill completado: $count filas actualizadas." . PHP_EOL;
echo "InfoFunes: $total artículos, $withKey con canonical_key." . PHP_EOL;
