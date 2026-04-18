<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';
Config::bootstrap();

header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');

$cssV = filemtime(__DIR__ . '/assets/css/style.css');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Acerca de FunesYa — Portal de noticias de Funes, Santa Fe</title>
    <meta name="description" content="FunesYa es un agregador de noticias local que reúne en tiempo real las novedades de Funes, Santa Fe, desde múltiples medios locales.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.funesya.com.ar/about.php">
    <link rel="alternate" type="application/rss+xml" title="FunesYa — Noticias de Funes" href="https://www.funesya.com.ar/rss.xml">

    <!-- Fuentes locales -->
    <link rel="preload" as="font" href="assets/fonts/inter-v20-latin.woff2" type="font/woff2" crossorigin>
    <link rel="preload" as="font" href="assets/fonts/outfit-v15-latin.woff2" type="font/woff2" crossorigin>

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

        .about-wrapper{max-width:780px;margin:48px auto;padding:0 20px 80px}

        .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--text-secondary);font-size:.875rem;margin-bottom:32px;transition:color .2s}
        .back-link:hover{color:var(--accent-color)}
        .back-link svg{width:16px;height:16px}

        .about-title{font-family:var(--font-heading);font-size:clamp(1.8rem,4vw,2.5rem);font-weight:700;line-height:1.2;margin-bottom:8px}
        .about-subtitle{color:var(--text-secondary);font-size:1rem;margin-bottom:40px;padding-bottom:32px;border-bottom:1px solid var(--card-border)}

        .about-section{margin-bottom:36px}
        .about-section h2{font-family:var(--font-heading);font-size:1.2rem;font-weight:600;margin-bottom:12px;color:var(--accent-color)}
        .about-section p{color:var(--text-secondary);line-height:1.85;margin-bottom:12px}
        .about-section p:last-child{margin-bottom:0}

        .sources-list{display:flex;flex-wrap:wrap;gap:10px;padding:0;list-style:none;margin-top:12px}
        .sources-list li{background:var(--card-bg);border:1px solid var(--card-border);border-radius:20px;padding:6px 16px;font-size:.875rem;color:var(--text-primary)}

        .accent-link{color:var(--accent-color);transition:opacity .2s}
        .accent-link:hover{opacity:.75;text-decoration:underline}

        @media(max-width:768px){
            .about-wrapper{margin:20px auto;padding:0 16px 60px}
        }
    </style>

    <link rel="preload" as="style" href="assets/css/style.css?v=<?= $cssV ?>" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="assets/css/style.css?v=<?= $cssV ?>"></noscript>
</head>
<body>
    <header class="navbar">
        <div class="container navbar-content">
            <a href="index.php" class="logo">Funes<span class="highlight">Ya</span></a>
        </div>
    </header>

    <main>
        <div class="about-wrapper">

            <a href="index.php" class="back-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                Volver al inicio
            </a>

            <h1 class="about-title">Acerca de <span style="background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent">FunesYa</span></h1>
            <p class="about-subtitle">El portal de noticias de Funes, Santa Fe</p>

            <div class="about-section">
                <h2>¿Qué es FunesYa?</h2>
                <p>FunesYa es el lugar donde encontrás todas las noticias de Funes y la región en un solo lugar. Reunimos automáticamente lo que publican los principales medios locales, para que no tengas que entrar a cada uno por separado.</p>
                <p>El sitio se actualiza solo, cada 2 minutos, sin que tengas que recargar nada. Abrís FunesYa y ya está: la información más reciente, siempre al día.</p>
            </div>

            <div class="about-section">
                <h2>¿Cómo funciona?</h2>
                <p>Cada artículo que aparece en FunesYa proviene de uno de los medios locales listados arriba. Al hacer clic en "Leer artículo", se muestra un resumen generado automáticamente y un enlace directo al artículo original en el medio de origen.</p>
                <p>FunesYa no produce contenido propio: es una ventana a la prensa local de Funes.</p>
            </div>

            <div class="about-section">
                <h2>RSS</h2>
                <p>Podés suscribirte al feed RSS de FunesYa para recibir las noticias en tu lector de feeds favorito.</p>
                <p><a href="rss.xml" class="accent-link" target="_blank" rel="noopener noreferrer">Suscribirse al feed RSS →</a></p>
            </div>

        </div>
    </main>

    <footer>
        <div class="container footer-content">
            <nav class="footer-nav" aria-label="Navegación del pie de página">
                <a href="index.php">Inicio</a>
                <a href="about.php">Acerca de</a>
                <a href="rss.xml" target="_blank" rel="noopener noreferrer">RSS</a>
            </nav>
            <p class="footer-copy">FunesYa &copy; <?= date('Y') ?>. Noticias en tiempo real de Funes, Santa Fe.</p>
        </div>
    </footer>
</body>
</html>
