<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/ArticleSummarizer.php';
require_once __DIR__ . '/src/ArticleLoader.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$db         = new Database();
$summarizer = new ArticleSummarizer($db);
$loader     = new ArticleLoader($db, $summarizer);
$data       = $loader->load($id);

if ($data === null) {
    header('Location: index.php');
    exit;
}

$title             = $data['title'];
$source            = $data['source'];
$pubDate           = $data['pubDate'];
$imageUrl          = $data['imageUrl'];
$externalLink      = $data['externalLink'];
$summaryParagraphs = $data['summaryParagraphs'];
$ogImageUrl        = $data['ogImageUrl'];
$pubDateIso        = $data['pubDateIso'];

// Valores para SEO (decodificados de HTML para uso en JSON-LD)
$canonicalUrl    = 'https://www.funesya.com.ar/article.php?id=' . $id;
$rawTitle        = htmlspecialchars_decode($title, ENT_QUOTES);
$rawSource       = htmlspecialchars_decode($source, ENT_QUOTES);
$rawDescription  = htmlspecialchars_decode($summaryParagraphs[0] ?? '', ENT_QUOTES);
$metaDescription = mb_strlen($rawDescription) > 160
    ? mb_substr($rawDescription, 0, 157) . '…'
    : ($rawDescription !== '' ? $rawDescription : $rawTitle);
$metaDescriptionEsc = htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- SEO básico -->
    <title><?= $title ?> — FunesYa</title>
    <meta name="description" content="<?= $metaDescriptionEsc ?>">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <link rel="canonical" href="<?= $canonicalUrl ?>">

    <!-- Open Graph (Facebook, WhatsApp, LinkedIn) -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= $canonicalUrl ?>">
    <meta property="og:site_name" content="FunesYa">
    <meta property="og:locale" content="es_AR">
    <meta property="og:title" content="<?= $title ?> — FunesYa">
    <meta property="og:description" content="<?= $metaDescriptionEsc ?>">
    <meta property="article:published_time" content="<?= $pubDateIso ?>">
    <meta property="article:author" content="<?= $source ?>">
    <meta property="article:section" content="Noticias locales">
    <?php if ($ogImageUrl !== ''): ?>
    <meta property="og:image" content="<?= $ogImageUrl ?>">
    <meta property="og:image:alt" content="<?= $title ?>">
    <?php endif; ?>

    <!-- Twitter / X Card -->
    <meta name="twitter:card" content="<?= $ogImageUrl !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= $title ?> — FunesYa">
    <meta name="twitter:description" content="<?= $metaDescriptionEsc ?>">
    <?php if ($ogImageUrl !== ''): ?>
    <meta name="twitter:image" content="<?= $ogImageUrl ?>">
    <meta name="twitter:image:alt" content="<?= $title ?>">
    <?php endif; ?>

    <!-- JSON-LD: NewsArticle -->
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type'    => 'NewsArticle',
        'headline' => $rawTitle,
        'description' => $rawDescription !== '' ? $rawDescription : $rawTitle,
        'url'      => $canonicalUrl,
        'datePublished' => $pubDateIso,
        'dateModified'  => $pubDateIso,
        'inLanguage' => 'es-AR',
        'image'    => $ogImageUrl !== '' ? htmlspecialchars_decode($ogImageUrl, ENT_QUOTES) : null,
        'author'   => [
            '@type' => 'Organization',
            'name'  => $rawSource,
        ],
        'publisher' => [
            '@type' => 'Organization',
            '@id'   => 'https://www.funesya.com.ar/#organization',
            'name'  => 'FunesYa',
            'url'   => 'https://www.funesya.com.ar/',
        ],
        'isPartOf' => [
            '@type' => 'WebSite',
            '@id'   => 'https://www.funesya.com.ar/#website',
            'name'  => 'FunesYa',
            'url'   => 'https://www.funesya.com.ar/',
        ],
        'breadcrumb' => [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => 'https://www.funesya.com.ar/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $rawTitle, 'item' => $canonicalUrl],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Outfit:wght@400;600;700&display=optional" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Outfit:wght@400;600;700&display=optional"></noscript>
    <?php $cssV = filemtime(__DIR__ . '/assets/css/style.css'); ?>
    <link rel="preload" as="style" href="assets/css/style.css?v=<?= $cssV ?>" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="assets/css/style.css?v=<?= $cssV ?>"></noscript>
    <!-- Google Analytics: cargado después del evento load para no bloquear FCP/LCP/TBT -->
    <script>
    window.addEventListener('load', function () {
        var s = document.createElement('script');
        s.src = 'https://www.googletagmanager.com/gtag/js?id=G-X7JKWCEVGL';
        s.async = true;
        document.head.appendChild(s);
        s.onload = function () {
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'G-X7JKWCEVGL', { 'transport_type': 'beacon' });
        };
    });
    </script>
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

        @media (max-width: 768px) {
            .article-wrapper {
                margin: 20px auto;
                padding: 0 16px 60px;
            }

            .article-title {
                font-size: clamp(1.3rem, 5vw, 1.8rem);
                margin-bottom: 20px;
            }

            .article-description {
                font-size: 1rem;
                line-height: 1.7;
                padding: 16px;
                margin-bottom: 28px;
            }

            .external-btn {
                width: 100%;
                justify-content: center;
                padding: 15px 20px;
                font-size: 0.95rem;
            }

            .back-link {
                margin-bottom: 20px;
            }
        }

        @media (max-width: 400px) {
            .article-wrapper {
                padding: 0 12px 50px;
            }

            .article-meta {
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <header class="navbar">
        <div class="container navbar-content">
            <a href="index.php" class="logo" style="text-decoration:none;">
                Funes<span class="highlight">Ya</span>
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
            <p>FunesYa &copy; <?= date('Y') ?>. Noticias en tiempo real de Funes, Santa Fe.</p>
        </div>
    </footer>
</body>
</html>
