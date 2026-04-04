<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/ArticleSummarizer.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$db      = new Database();
$article = $db->getNewsById($id);

if (!$article) {
    header('Location: index.php');
    exit;
}

$title        = htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8');
$source       = htmlspecialchars($article['source'], ENT_QUOTES, 'UTF-8');
$pubDate      = date('d \d\e F \d\e Y, H:i', strtotime($article['pub_date']));
// Dominios con hotlink protection: usar proxy para no recibir imagen de acceso denegado
const PROXY_DOMAINS = [
    'lavozdefunes.com.ar',
    'estacionline.com',
    'funeshoy.com.ar',
    'eloccidental.com.ar',
    'fmdiezfunes.com.ar',
];

$rawImageUrl  = $article['image_url'] ?? '';
$imageHost    = strtolower(parse_url($rawImageUrl, PHP_URL_HOST) ?? '');
$needsProxy   = array_reduce(PROXY_DOMAINS, fn($carry, $d) =>
    $carry || $imageHost === $d || str_ends_with($imageHost, '.' . $d), false);
$imageUrl     = $needsProxy
    ? 'api/img.php?url=' . urlencode($rawImageUrl)
    : htmlspecialchars($rawImageUrl, ENT_QUOTES, 'UTF-8');

// Validar que el enlace externo use un esquema seguro (http/https)
// para prevenir inyección de URLs tipo javascript: o data:
$rawLink      = $article['link'];
$parsedScheme = strtolower(parse_url($rawLink, PHP_URL_SCHEME) ?? '');
$externalLink = in_array($parsedScheme, ['http', 'https'], true)
    ? htmlspecialchars($rawLink, ENT_QUOTES, 'UTF-8')
    : htmlspecialchars('index.php', ENT_QUOTES, 'UTF-8');

// Usar descripción guardada o generarla con IA si es un snippet de RSS o no existe
$rawSummary = $article['description'];
$isRssSnippet = $rawSummary && str_ends_with(rtrim($rawSummary), '...');
if ((!$rawSummary || $isRssSnippet) && !str_starts_with($article['link'], 'https://example.com')) {
    $rawSummary = (new ArticleSummarizer($db))->getSummary($article) ?? $rawSummary;
}

$summaryParagraphs = [];
if ($rawSummary) {
    foreach (explode("\n\n", $rawSummary) as $para) {
        $para = trim($para);
        if ($para !== '') {
            $summaryParagraphs[] = htmlspecialchars($para, ENT_QUOTES, 'UTF-8');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> — FunesNews</title>
    <meta name="description" content="<?= $summaryParagraphs[0] ?? $title ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .article-wrapper {
            max-width: 780px;
            margin: 48px auto;
            padding: 0 20px 80px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-secondary);
            font-size: .875rem;
            margin-bottom: 28px;
            transition: color .2s;
        }
        .back-link:hover { color: var(--accent-color); }
        .back-link svg { width: 16px; height: 16px; }

        .article-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .article-source {
            background: var(--gradient);
            color: #000;
            font-size: .75rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            font-family: var(--font-heading);
        }
        .article-date {
            color: var(--text-secondary);
            font-size: .85rem;
        }

        .article-title {
            font-family: var(--font-heading);
            font-size: clamp(1.5rem, 4vw, 2.2rem);
            font-weight: 700;
            line-height: 1.25;
            margin-bottom: 28px;
            color: var(--text-primary);
        }

        .article-image {
            width: 100%;
            max-height: 480px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 32px;
            border: 1px solid var(--card-border);
        }

        .article-description {
            font-size: 1.125rem;
            line-height: 1.8;
            color: var(--text-secondary);
            margin-bottom: 40px;
            padding: 24px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-left: 3px solid var(--accent-color);
            border-radius: 0 8px 8px 0;
        }
        .article-description p { margin: 0 0 14px; }
        .article-description p:last-child { margin-bottom: 0; }

        .no-description {
            color: var(--text-secondary);
            font-style: italic;
            margin-bottom: 40px;
            font-size: .95rem;
        }

        .external-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            background: var(--gradient);
            color: #000;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1rem;
            border-radius: 8px;
            transition: opacity .2s, transform .2s;
        }
        .external-btn:hover {
            opacity: .88;
            transform: translateY(-1px);
        }
        .external-btn svg { width: 18px; height: 18px; }

        .external-note {
            margin-top: 12px;
            font-size: .8rem;
            color: var(--text-secondary);
        }
    </style>
</head>
<body>
    <header class="navbar">
        <div class="container navbar-content">
            <a href="index.php" class="logo" style="text-decoration:none;">
                Funes<span class="highlight">News</span>
            </a>
        </div>
    </header>

    <main>
        <div class="article-wrapper">

            <a href="index.php" class="back-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                Volver al inicio
            </a>

            <div class="article-meta">
                <span class="article-source"><?= $source ?></span>
                <span class="article-date"><?= $pubDate ?></span>
            </div>

            <h1 class="article-title"><?= $title ?></h1>

            <?php if ($imageUrl): ?>
                <img
                    src="<?= $imageUrl ?>"
                    alt="<?= $title ?>"
                    class="article-image"
                    width="1200"
                    height="630"
                    onerror="this.style.display='none'"
                >
            <?php endif; ?>

            <?php if (!empty($summaryParagraphs)): ?>
                <div class="article-description">
                    <?php foreach ($summaryParagraphs as $para): ?>
                        <p><?= $para ?></p>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-description">No fue posible generar un resumen para esta noticia.</p>
            <?php endif; ?>

            <a href="<?= $externalLink ?>" target="_blank" rel="noopener noreferrer" class="external-btn">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
                Leer nota completa en <?= $source ?>
            </a>
            <p class="external-note">Serás redirigido al sitio de <?= $source ?></p>

        </div>
    </main>

    <footer>
        <div class="container text-center" style="padding: 20px 0; color: var(--text-secondary); font-size: .8rem;">
            &copy; <?= date('Y') ?> FunesNews
        </div>
    </footer>
</body>
</html>
