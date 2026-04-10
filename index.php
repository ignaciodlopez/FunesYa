<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FunesYa - Tu portal de noticias locales</title>
    <meta name="description" content="Las últimas noticias de la ciudad de Funes, Santa Fe. Se actualiza cada 2 minutos de múltiples fuentes locales.">
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
    <!-- Custom CSS con cache-buster basado en fecha de modificación del archivo -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
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
        <!-- Grilla principal de noticias, populada dinámicamente desde la API -->
        <section class="news-grid" id="news-container">
            <!-- Estado de carga inicial (skeleton) mientras se obtienen las noticias -->
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
            <p>FunesYa &copy; <?= date('Y') ?>. Noticias en tiempo real.</p>
        </div>
    </footer>

    <script src="assets/js/main.js?v=<?= filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
</body>
</html>
