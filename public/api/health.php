<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../src/Database.php';

try {
    $db = new Database();

    $statusFile = __DIR__ . '/../../data/aggregator_status.json';
    $aggregatorStatus = null;
    if (is_file($statusFile)) {
        $rawStatus = file_get_contents($statusFile);
        if ($rawStatus !== false && $rawStatus !== '') {
            $aggregatorStatus = json_decode($rawStatus, true);
        }
    }

    $sourceStats = [];
    foreach ($db->getSourceStats() as $row) {
        $sourceName = (string)$row['source'];
        $sourceStats[$sourceName] = [
            'total_articles' => (int)$row['total_articles'],
            'recent_articles' => (int)$row['recent_articles'],
            'latest_pub_date' => $row['latest_pub_date'],
            'latest_title' => $row['latest_title'],
            'articles_with_image' => (int)$row['articles_with_image'],
            'placeholder_images' => (int)$row['placeholder_images'],
        ];
    }

    $aggregatorSources = $aggregatorStatus['sources'] ?? [];
    foreach ($aggregatorSources as $sourceName => $status) {
        $sourceStats[$sourceName] = array_merge($sourceStats[$sourceName] ?? [], [
            'fetch_state' => $status['state'] ?? 'unknown',
            'fetch_type' => $status['type'] ?? null,
            'fetch_url' => $status['url'] ?? null,
            'items_fetched' => (int)($status['items_fetched'] ?? 0),
            'ready_to_save' => (int)($status['ready_to_save'] ?? 0),
            'fetched_latest_pub_date' => $status['latest_pub_date'] ?? null,
            'message' => $status['message'] ?? null,
        ]);
    }

    echo json_encode([
        'status' => 'success',
        'last_update' => date('Y-m-d H:i:s', $db->getLastUpdate()),
        'aggregator' => [
            'generated_at' => $aggregatorStatus['generated_at'] ?? null,
            'processed' => (int)($aggregatorStatus['processed'] ?? 0),
            'saved' => (int)($aggregatorStatus['saved'] ?? 0),
        ],
        'sources' => $sourceStats,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('[FunesYa][api/health.php] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'No se pudo obtener el estado del agregador.',
    ]);
}