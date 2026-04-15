<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Config.php';

Config::bootstrap();

// Dominios con hotlink protection: las imágenes se sirven vía proxy
const SSR_PROXY_DOMAINS = [
    'lavozdefunes.com.ar', 'estacionline.com', 'flex-assets.tadevel-cdn.com',
    'funeshoy.com.ar', 'eloccidental.com.ar', 'fmdiezfunes.com.ar',
    'infobae.com', 'tn.com.ar', 'radiofonica.com', 'ambito.com',
    'media.ambito.com', 'elliberador.com', 'resizer.glanacion.com',
];

function ssrIsUsableImage(string $url): bool {
    return $url !== '' && !preg_match('~picsum\.photos|images\.unsplash\.com~i', $url);
}

function ssrResolveImgSrc(string $url): string {
    if (!ssrIsUsableImage($url)) return '';
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    foreach (SSR_PROXY_DOMAINS as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) {
            return 'api/img.php?url=' . urlencode($url);
        }
    }
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function ssrSourceInitials(string $source): string {
    $words = preg_split('/\s+/', trim($source), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) return 'FN';
    if (count($words) === 1) return strtoupper(mb_substr($words[0], 0, 2));
    return strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
}

function ssrFormatDate(string $pubDate): string {
    static $months = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    $ts = strtotime($pubDate);
    if ($ts === false) return '';
    return sprintf('%02d %s %d', (int)date('d', $ts), $months[(int)date('n', $ts) - 1], (int)date('Y', $ts));
}

$db          = new Database();
$ssrNews     = $db->getNews(12);
$ssrSources  = $db->getSources();
$lastUpdate  = $db->getLastUpdate();

$ssrIds        = array_column($ssrNews, 'id');
$ssrHasMore    = count($ssrNews) === 12;
$ssrLastUpdate = $lastUpdate ? date('Y-m-d H:i:s', $lastUpdate) : '';
$allSources    = array_merge(['Todas'], $ssrSources);

// Primera imagen válida → hint de preload para el elemento LCP
$lcpImageUrl = '';
foreach ($ssrNews as $_item) {
    $raw = trim((string)($_item['image_url'] ?? ''));
    if (ssrIsUsableImage($raw)) {
        $lcpImageUrl = ssrResolveImgSrc($raw);
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- SEO básico -->
    <title>FunesYa — Noticias de Funes, Santa Fe en tiempo real</title>
    <meta name="description" content="Las últimas noticias de Funes, Santa Fe. Actualizadas cada 2 minutos desde múltiples medios locales: InfoFunes, La Voz de Funes, Funes Hoy y más.">
    <meta name="keywords" content="noticias Funes, Santa Fe, noticias locales Funes, FunesYa, periodismo local, información Funes">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <link rel="canonical" href="https://www.funesya.com.ar/">

    <!-- RSS autodiscovery -->
    <link rel="alternate" type="application/rss+xml" title="FunesYa — Noticias de Funes" href="https://www.funesya.com.ar/rss.xml">

    <!-- Open Graph (Facebook, WhatsApp, LinkedIn) -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.funesya.com.ar/">
    <meta property="og:site_name" content="FunesYa">
    <meta property="og:locale" content="es_AR">
    <meta property="og:title" content="FunesYa — Noticias de Funes, Santa Fe en tiempo real">
    <meta property="og:description" content="Las últimas noticias de Funes, Santa Fe. Actualizadas cada 2 minutos desde múltiples medios locales.">

    <!-- Twitter / X Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="FunesYa — Noticias de Funes, Santa Fe en tiempo real">
    <meta name="twitter:description" content="Las últimas noticias de Funes, Santa Fe. Actualizadas cada 2 minutos desde múltiples medios locales.">

    <!-- JSON-LD: WebSite + Organization -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "WebSite",
          "@id": "https://www.funesya.com.ar/#website",
          "url": "https://www.funesya.com.ar/",
          "name": "FunesYa",
          "description": "Portal de noticias locales de Funes, Santa Fe, Argentina.",
          "inLanguage": "es-AR",
          "publisher": { "@id": "https://www.funesya.com.ar/#organization" }
        },
        {
          "@type": "Organization",
          "@id": "https://www.funesya.com.ar/#organization",
          "name": "FunesYa",
          "url": "https://www.funesya.com.ar/",
          "description": "Agregador de noticias locales de Funes, Santa Fe, Argentina."
        }
      ]
    }
    </script>

    <!-- Google Fonts: carga asíncrona para no bloquear el render (FCP) -->
    <!-- preconnect reduce la latencia DNS+TCP+TLS antes de que el CSS las solicite -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- preload + onload: descarga la hoja de fuentes sin bloquear el render.
         Mientras no carga, el browser usa la fuente del sistema (display=swap). -->
    <link rel="preload" as="style"
          href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Outfit:wght@400;600;700&display=swap"
          onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Outfit:wght@400;600;700&display=swap">
    </noscript>
    <!-- Preload de la imagen LCP: el browser la descarga en paralelo antes de parsear el body -->
    <?php if ($lcpImageUrl !== ''): ?>
    <link rel="preload" as="image" href="<?= $lcpImageUrl ?>" fetchpriority="high">
    <?php endif; ?>
    <!-- Custom CSS con cache-buster basado en fecha de modificación del archivo -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-X7JKWCEVGL"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-X7JKWCEVGL');
    </script>
</head>
<body>
    <!-- Premium Dark Header -->
    <header class="navbar">
        <div class="container navbar-content">
            <h1 class="logo">Funes<span class="highlight">Ya</span></h1>
            <nav>
                <div class="pill-nav" id="source-filters">
                    <button class="pill active" data-source="Todas">Todas</button>
                    <!-- Filtros de fuente generados dinámicamente desde JavaScript -->
                </div>
            </nav>
            <div class="actions">
                <button id="refresh-btn" class="icon-btn" title="Actualizar manualmente">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- Grilla principal de noticias -->
        <section class="news-grid" id="news-container">
        <?php if (!empty($ssrNews)): ?>
            <?php foreach ($ssrNews as $i => $ssrItem):
                $rawImg   = trim((string)($ssrItem['image_url'] ?? ''));
                $hasImg   = ssrIsUsableImage($rawImg);
                $imgSrc   = $hasImg ? ssrResolveImgSrc($rawImg) : '';
                $t        = htmlspecialchars($ssrItem['title'],  ENT_QUOTES, 'UTF-8');
                $s        = htmlspecialchars($ssrItem['source'], ENT_QUOTES, 'UTF-8');
                $initials = htmlspecialchars(ssrSourceInitials($ssrItem['source']), ENT_QUOTES, 'UTF-8');
                $dateStr  = ssrFormatDate($ssrItem['pub_date']);
                $rawImgEsc = htmlspecialchars($rawImg, ENT_QUOTES, 'UTF-8');
            ?>
            <article class="news-card" data-id="<?= (int)$ssrItem['id'] ?>">
                <div class="card-img-wrapper<?= $hasImg ? '' : ' no-image' ?>" data-source="<?= $s ?>">
                    <?php if ($hasImg): ?>
                        <img src="<?= $imgSrc ?>"
                             alt="<?= $t ?>"
                             data-original-src="<?= $rawImgEsc ?>"
                             <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>
                             width="640" height="360">
                    <?php else: ?>
                        <div class="card-media-placeholder" aria-hidden="true">
                            <div class="card-media-glyph"><?= $initials ?></div>
                            <div class="card-media-text">Cobertura sin imagen</div>
                        </div>
                    <?php endif; ?>
                    <span class="card-source"><?= $s ?></span>
                </div>
                <div class="card-content">
                    <h2 class="card-title"><?= $t ?></h2>
                    <div class="card-footer">
                        <span class="card-date"><?= $dateStr ?></span>
                        <a href="article.php?id=<?= (int)$ssrItem['id'] ?>" class="read-more">Leer artículo</a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="news-card skeleton">
                <div class="skeleton-img"></div>
                <div class="card-content">
                    <div class="skeleton-text title"></div>
                    <div class="skeleton-text"></div>
                    <div class="skeleton-text short"></div>
                </div>
            </div>
            <div class="news-card skeleton">
                <div class="skeleton-img"></div>
                <div class="card-content">
                    <div class="skeleton-text title"></div>
                    <div class="skeleton-text"></div>
                    <div class="skeleton-text short"></div>
                </div>
            </div>
            <div class="news-card skeleton">
                <div class="skeleton-img"></div>
                <div class="card-content">
                    <div class="skeleton-text title"></div>
                    <div class="skeleton-text"></div>
                    <div class="skeleton-text short"></div>
                </div>
            </div>
        <?php endif; ?>
        </section>
        
        <!-- Indicador de carga mientras se actualizan las noticias desde la API -->
        <div id="loader" class="loader hidden">
            <div class="spinner"></div>
            <p>Actualizando noticias...</p>
        </div>
        
        <!-- Paginación: permite cargar más noticias sin recargar la página -->
        <div id="load-more-container" class="load-more-container hidden text-center">
            <button id="load-more-btn" class="pill action-btn">Cargar más noticias</button>
        </div>
    </main>

    <!-- Pie de página del sitio -->
    <footer>
        <div class="container text-center">
            <p>FunesYa &copy; <?= date('Y') ?>. Noticias en tiempo real de Funes, Santa Fe.</p>
        </div>
    </footer>

    <!-- Datos SSR para hidratación del JS sin re-fetch inicial -->
    <script>
    window.__SSR__ = <?= json_encode([
        'ids'        => $ssrIds,
        'lastUpdate' => $ssrLastUpdate,
        'sources'    => $allSources,
        'hasMore'    => $ssrHasMore,
    ], JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="assets/js/main.js?v=<?= filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
</body>
</html>
