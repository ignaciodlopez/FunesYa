<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Config.php';

Config::bootstrap();

// Dom. con hotlink protection: fuente única en Config::getProxyDomains()

function ssrIsUsableImage(string $url): bool {
    return $url !== '' && !preg_match('~picsum\.photos|images\.unsplash\.com~i', $url);
}

function ssrNeedsProxy(string $url): bool {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    foreach (Config::getProxyDomains() as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) return true;
    }
    return false;
}

function ssrResolveImgSrc(string $url, int $w = 640): string {
    if (!ssrIsUsableImage($url)) return '';
    if (ssrNeedsProxy($url)) {
        return 'api/img.php?url=' . urlencode($url) . '&w=' . $w;
    }
    // Dominio sin hotlink: URL directa (sin overhead del proxy en caché fría)
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function ssrBuildSrcset(string $url, array $widths = [320, 640]): string {
    if (!ssrIsUsableImage($url) || !ssrNeedsProxy($url)) return '';
    $parts = [];
    foreach ($widths as $w) {
        $parts[] = 'api/img.php?url=' . urlencode($url) . '&w=' . $w . ' ' . $w . 'w';
    }
    return implode(', ', $parts);
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
// Se usa la URL con proxy+w=640 (idéntica al src que tendrá el primer <img>)
$lcpImageUrl = '';
foreach ($ssrNews as $_item) {
    $raw = trim((string)($_item['image_url'] ?? ''));
    if (ssrIsUsableImage($raw)) {
        $lcpImageUrl = ssrResolveImgSrc($raw, 640);
        break;
    }
}
header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
?>
<!DOCTYPE html>
<html lang="es" style="background:#0f1115">
<head>
    <meta charset="UTF-8">
    <meta name="color-scheme" content="dark">
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

    <!-- Fuentes locales: Inter y Outfit servidas desde assets/fonts/ (sin dependencia externa) -->
    <link rel="preload" as="font" href="assets/fonts/inter-v20-latin.woff2" type="font/woff2" crossorigin>
    <link rel="preload" as="font" href="assets/fonts/outfit-v15-latin.woff2" type="font/woff2" crossorigin>
    <!-- Preload de la imagen LCP: el browser la descarga en paralelo antes de parsear el body -->
    <?php if ($lcpImageUrl !== ''): ?>
    <link rel="preload" as="image" href="<?= $lcpImageUrl ?>" fetchpriority="high">
    <?php endif; ?>
    <!-- CSS crítico inlineado: elimina el request bloqueante de render para el CSS completo.
         Contiene solo los estilos necesarios para el contenido visible inicial (above-the-fold).
         El CSS completo se carga de forma asíncrona abajo. -->
    <style>
    :root{--bg-color:#0f1115;--card-bg:#161920;--card-border:#222630;--text-primary:#f0f2f5;--text-secondary:#8b92a5;--accent-color:#00d4ff;--gradient:linear-gradient(135deg,#00d4ff 0%,#0070f3 100%);--font-heading:'Outfit',sans-serif;--font-body:'Inter',sans-serif}
    *{box-sizing:border-box;margin:0;padding:0}
    body{background-color:var(--bg-color);color:var(--text-primary);font-family:var(--font-body);-webkit-font-smoothing:antialiased;line-height:1.5}
    a{text-decoration:none;color:inherit}
    .container{max-width:1200px;margin:0 auto;padding:0 20px}
    .navbar{background:rgba(15,17,21,.8);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);position:sticky;top:0;z-index:100;border-bottom:1px solid var(--card-border);padding:15px 0}
    .navbar-content{display:flex;justify-content:space-between;align-items:center}
    .logo{font-family:var(--font-heading);font-weight:700;font-size:1.5rem;letter-spacing:-.5px}
    .logo .highlight{background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
    .pill-nav{display:flex;gap:10px;background:var(--card-bg);padding:5px;border-radius:30px;border:1px solid var(--card-border)}
    .pill{background:transparent;border:none;color:var(--text-secondary);padding:8px 16px;border-radius:20px;font-family:var(--font-body);font-weight:600;font-size:.9rem;cursor:pointer}
    .pill.active{background:var(--text-primary);color:var(--bg-color)}
    .icon-btn{background:none;border:none;color:var(--text-secondary);cursor:pointer;width:36px;height:36px;display:flex;justify-content:center;align-items:center;border-radius:50%}
    .icon-btn svg{width:20px;height:20px}
    main{padding:40px 0;min-height:80vh}
    .news-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:25px}
    .news-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;content-visibility:auto;contain-intrinsic-size:320px 400px}
    .card-img-wrapper{position:relative;width:100%;padding-top:56.25%;overflow:hidden}
    .card-img-wrapper img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover}
    .card-img-wrapper.no-image{background:linear-gradient(180deg,rgba(15,23,42,.96),rgba(12,18,30,.92))}
    .card-media-placeholder{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:22px;text-align:center}
    .card-media-glyph{width:48px;height:48px;display:flex;align-items:center;justify-content:center;border-radius:999px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);color:var(--text-primary);font-family:var(--font-heading);font-weight:600;font-size:.95rem;letter-spacing:.06em}
    .card-media-text{font-size:.68rem;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--text-secondary)}
    .card-source{position:absolute;top:15px;left:15px;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);padding:4px 10px;border-radius:4px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
    .card-content{padding:20px;display:flex;flex-direction:column;flex-grow:1}
    .card-title{font-family:var(--font-heading);font-size:1.25rem;font-weight:600;margin-bottom:15px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
    .card-footer{margin-top:auto;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--card-border);padding-top:15px}
    .card-date{font-size:.85rem;color:var(--text-secondary)}
    .read-more{font-size:.9rem;font-weight:600;color:var(--accent-color);display:inline-flex;align-items:center;gap:4px}
    .read-more::after{content:'→'}
    .loader.hidden,.load-more-container.hidden{display:none}
    @media(max-width:768px){
      .navbar{padding:10px 0}
      .navbar-content{display:grid;grid-template-areas:"logo actions" "nav nav";grid-template-columns:1fr auto;align-items:center;gap:10px}
      .logo{grid-area:logo}nav{grid-area:nav}.actions{grid-area:actions}
      .pill-nav{overflow-x:auto;flex-wrap:nowrap;justify-content:flex-start;scrollbar-width:none}
      .pill-nav::-webkit-scrollbar{display:none}
      .pill{flex-shrink:0;padding:10px 18px}
      .icon-btn{width:40px;height:40px}
      main{padding:20px 0 40px}
      .news-grid{gap:14px}
      .card-content{padding:14px 16px}
      .card-title{font-size:1.05rem;margin-bottom:10px}
      .card-footer{padding-top:10px}
      .card-date{font-size:.8rem}
    }
    @media(max-width:400px){
      .container{padding:0 12px}.logo{font-size:1.3rem}
      .news-grid{grid-template-columns:1fr}
      .pill{padding:9px 14px;font-size:.82rem}
    }
    </style>
    <!-- CSS completo: carga asíncrona (no bloqueante) para efectos hover, animaciones, footer, etc. -->
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
</head>
<body>
    <!-- Premium Dark Header -->
    <header class="navbar">
        <div class="container navbar-content">
            <h1 class="logo">Funes<span class="highlight">Ya</span></h1>
            <nav>
                <div class="pill-nav" id="source-filters">
                    <?php foreach ($allSources as $src):
                        $srcEsc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                    ?>
                    <button class="pill <?= $src === 'Todas' ? 'active' : '' ?>" data-source="<?= $srcEsc ?>"><?= $srcEsc ?></button>
                    <?php endforeach; ?>
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
                $imgSrc    = $hasImg ? ssrResolveImgSrc($rawImg, 640) : '';
                $imgSrcset = $hasImg ? ssrBuildSrcset($rawImg) : '';
                $t         = htmlspecialchars($ssrItem['title'],  ENT_QUOTES, 'UTF-8');
                $s        = htmlspecialchars($ssrItem['source'], ENT_QUOTES, 'UTF-8');
                $initials = htmlspecialchars(ssrSourceInitials($ssrItem['source']), ENT_QUOTES, 'UTF-8');
                $dateStr  = ssrFormatDate($ssrItem['pub_date']);
                $rawImgEsc = htmlspecialchars($rawImg, ENT_QUOTES, 'UTF-8');
            ?>
            <article class="news-card" data-id="<?= (int)$ssrItem['id'] ?>">
                <div class="card-img-wrapper<?= $hasImg ? '' : ' no-image' ?>" data-source="<?= $s ?>">
                    <?php if ($hasImg): ?>
                        <img src="<?= $imgSrc ?>"
                             <?= $imgSrcset !== '' ? 'srcset="' . $imgSrcset . '" sizes="(max-width: 400px) 320px, 640px"' : '' ?>
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
        <div class="container footer-content">
            <nav class="footer-nav" aria-label="Navegación del pie de página">
                <a href="about.php">Acerca de</a>
                <a href="rss.xml" target="_blank" rel="noopener noreferrer">RSS</a>
            </nav>
            <p class="footer-copy">FunesYa &copy; <?= date('Y') ?>. Noticias en tiempo real de Funes, Santa Fe.</p>
        </div>
    </footer>

    <!-- Datos SSR para hidratación del JS sin re-fetch inicial -->
    <script>
    window.__SSR__ = <?= json_encode([
        'ids'            => $ssrIds,
        'lastUpdate'     => $ssrLastUpdate,
        'sources'        => $allSources,
        'hasMore'        => $ssrHasMore,
        'hotlinkDomains' => Config::getProxyDomains(),
    ], JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="assets/js/main.js?v=<?= filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
</body>
</html>
