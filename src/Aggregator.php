<?php
declare(strict_types=1);

/**
 * Agrega noticias desde múltiples fuentes RSS locales de Funes.
 * Genera datos de ejemplo (mock) cuando un feed no está disponible.
 */
class Aggregator {
    private Database $db;
    private string $logFile;

    private function log(string $message): void {
        // Rotar log cuando supera 500 KB: conservar las últimas 300 líneas
        if (file_exists($this->logFile) && filesize($this->logFile) > 512000) {
            $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            file_put_contents($this->logFile, implode(PHP_EOL, array_slice($lines, -300)) . PHP_EOL);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /** @var array<string, string> Feeds RSS indexados por nombre del medio */
    private $feeds = [
        'InfoFunes' => 'https://infofunes.com.ar/rss.xml',
        'La Voz de Funes' => 'https://lavozdefunes.com.ar/rss',
        'Funes Hoy' => 'https://funeshoy.com.ar/feed/',
        'El Occidental' => 'https://eloccidental.com.ar/feed/',
        'Estacionline' => 'https://estacionline.com/feed/'
    ];

    /** @var array<string, string> Sitios sin RSS que se scrapean directamente */
    private $scrapers = [
        'FM Diez Funes' => 'https://www.fmdiezfunes.com.ar/noticias.php'
    ];

    /** @param Database $db Instancia de la base de datos para persistir las noticias obtenidas */
    public function __construct(Database $db) {
        $this->db = $db;
        $this->logFile = __DIR__ . '/../data/aggregator.log';
    }

    /**
     * Recorre todos los feeds configurados, parsea sus ítems y los persiste en la base de datos.
     * Actualiza la marca de tiempo de la última sincronización.
     */
    public function fetchAll(): void {
        $newsList = [];
        $this->log('--- Inicio ciclo de actualización ---');

        foreach ($this->feeds as $name => $url) {
            $feedItems = $this->parseFeed($url, $name);
            $newsList = array_merge($newsList, $feedItems);
        }

        foreach ($this->scrapers as $name => $url) {
            $scrapedItems = $this->scrapeHtmlPage($url, $name);
            $newsList = array_merge($newsList, $scrapedItems);
        }

        $saved = 0;
        if (!empty($newsList)) {
            $newsList = $this->deduplicateItems($newsList);
            $newsList = $this->removeSiteWideImages($newsList);
            $saved = $this->db->saveNews($newsList);
        }

        $this->log('Procesados: ' . count($newsList) . ', nuevos guardados: ' . $saved);
        
        $this->db->setLastUpdate(time());
    }

    /**
     * Reemplaza con placeholder Picsum las imágenes que aparecen en 2+ artículos
     * de la misma fuente dentro del mismo ciclo — señal de imagen genérica del sitio.
     */
    private function removeSiteWideImages(array $items): array {
        // Contar frecuencia de cada imagen por fuente
        $freq = [];
        foreach ($items as $item) {
            $url = $item['image_url'] ?? '';
            if ($url === '' || str_contains($url, 'picsum.photos')) continue;
            $freq[$item['source']][$url] = ($freq[$item['source']][$url] ?? 0) + 1;
        }

        foreach ($items as &$item) {
            $url = $item['image_url'] ?? '';
            if ($url === '' || str_contains($url, 'picsum.photos')) continue;
            if (($freq[$item['source']][$url] ?? 0) >= 2) {
                $seed = substr(md5($item['link']), 0, 8);
                $item['image_url'] = "https://picsum.photos/seed/{$seed}/600/400";
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Calcula una clave canónica para deduplicación.
     * Algunos medios (como InfoFunes) cambian el slug de la URL pero conservan
     * un identificador hexadecimal único al final; ese ID es la clave real del artículo.
     * Para el resto de fuentes se usa la URL completa en minúsculas sin trailing slash.
     *
     * @param string $link   URL del artículo
     * @param string $source Nombre del medio
     * @return string        Clave canónica de deduplicación
     */
    private function canonicalKey(string $link, string $source): string {
        $normalized = rtrim(strtolower($link), '/');

        // InfoFunes: la URL termina en _<hexhash> (ej. _a69d106b20041d9a647fb07ec)
        if ($source === 'InfoFunes' && preg_match('/_([0-9a-f]{16,})$/', $normalized, $m)) {
            return 'infofunes:' . $m[1];
        }

        return $normalized;
    }

    /**
     * Elimina artículos duplicados del lote, usando la clave canónica de cada enlace.
     * También descarta ítems cuya clave canónica ya existe en la base de datos.
     *
     * @param array $items Lista de noticias recién obtenidas
     * @return array       Lista sin duplicados
     */
    private function deduplicateItems(array $items): array {
        // Construir set de claves ya persistidas en BD (solo fuentes que lo requieren)
        $existingBySource = [];
        foreach ($items as $item) {
            $existingBySource[$item['source']] = true;
        }
        $existingKeys = [];
        foreach (array_keys($existingBySource) as $source) {
            foreach ($this->db->getLinksBySource($source) as $link) {
                $existingKeys[$this->canonicalKey($link, $source)] = true;
            }
        }

        $seen    = [];
        $deduped = [];
        foreach ($items as $item) {
            $key = $this->canonicalKey($item['link'], $item['source']);
            if (isset($seen[$key]) || isset($existingKeys[$key])) {
                continue;
            }
            $seen[$key] = true;
            // Solo se guarda canonical_key cuando es distinta a la URL normalizada,
            // es decir, para fuentes que reutilizan un ID estable (ej. InfoFunes).
            $item['canonical_key'] = str_starts_with($key, 'infofunes:') ? $key : null;
            $deduped[] = $item;
        }

        return $deduped;
    }

    /**
     * Descarga y parsea un feed RSS. Retorna datos mock si el feed no está disponible.
     *
     * @param string $url        URL del feed RSS
     * @param string $sourceName Nombre del medio de comunicación
     * @return array             Lista de noticias con título, link, imagen, fuente y fecha
     */
    private function parseFeed(string $url, string $sourceName): array {
        $items = [];
        $ctx = stream_context_create([
            'http' => ['timeout' => 5, 'user_agent' => 'FunesNewsAgent/1.0'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $rssContent = @file_get_contents($url, false, $ctx);
        if ($rssContent === false || $rssContent === '') {
            $this->log("[ERROR] {$sourceName}: no se pudo descargar el feed ({$url})");
            return [];
        }

        // Verificar código HTTP — rechazar respuestas de error antes de parsear
        if (!empty($http_response_header)) {
            if (preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $hm) && (int)$hm[1] >= 400) {
                $this->log("[ERROR] {$sourceName}: feed retornó HTTP {$hm[1]} ({$url})");
                return [];
            }
        }

        libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($rssContent);
            libxml_clear_errors();
            if ($xml && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    // Decodificar entities en título y link antes de cualquier uso
                    $title = trim(html_entity_decode((string)$item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $link  = trim(html_entity_decode((string)$item->link,  ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    // Descartar ítems sin título o sin link válido
                    if ($title === '' || $link === '' || !str_starts_with($link, 'http')) continue;

                    $image = $this->extractImage($item);
                    $pubDateStr = (string)$item->pubDate;
                    $pubDateTimestamp = strtotime($pubDateStr) ?: time();

                    // Extraer descripción: limpiar HTML y limitar a 400 caracteres
                    $rawDesc = (string)$item->description;
                    $description = trim(html_entity_decode(strip_tags($rawDesc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if (strlen($description) > 400) {
                        $description = mb_substr($description, 0, 397) . '...';
                    }

                    $items[] = [
                        'title'       => $title,
                        'link'        => $link,
                        'image_url'   => $image,
                        'source'      => $sourceName,
                        'pub_date'    => date('Y-m-d H:i:s', $pubDateTimestamp),
                        'description' => $description ?: null,
                    ];
                }
                $this->log("[OK] {$sourceName}: " . count($items) . " artículos obtenidos");
            } else {
                libxml_clear_errors();
                $this->log("[WARN] {$sourceName}: feed inválido o sin ítems");
                return [];
            }
        } catch (Exception $e) {
            libxml_clear_errors();
            $this->log("[ERROR] {$sourceName}: excepción al parsear — " . $e->getMessage());
            return [];
        }

        return $items;
    }

    /**
     * Extrae la URL de la imagen principal de un ítem RSS.
     * Prioridad: enclosure > media:content > content:encoded > description >
     *            og:image de la página original > placeholder Picsum.
     * Se usa og:image como fallback (no como primera opción) para evitar
     * una petición HTTP extra cuando el RSS ya embedía la imagen correcta.
     *
     * @param SimpleXMLElement $item Ítem del feed RSS
     * @return string                URL de la imagen o del placeholder
     */
    private function extractImage(SimpleXMLElement $item): string {
        $link = trim((string)$item->link);

        // 1º: enclosure del RSS
        if (isset($item->enclosure)) {
            foreach ($item->enclosure as $enc) {
                $type = (string)$enc['type'];
                if (strpos($type, 'image/') !== false) {
                    $url = trim((string)$enc['url']);
                    if ($url !== '' && str_starts_with($url, 'http') && stripos($url, '.gif') === false) return $url;
                }
            }
        }

        // 2º: media:content
        $media = $item->children('media', true);
        if (isset($media->content)) {
            $url = trim((string)$media->content->attributes()->url);
            if ($url !== '' && str_starts_with($url, 'http') && stripos($url, '.gif') === false) return $url;
        }

        // 3º: primer <img> en content:encoded (excluyendo contenedores de anuncios)
        $content = $item->children('content', true);
        if (isset($content->encoded)) {
            $encodedHtml = (string)$content->encoded;
            // Eliminar bloques de publicidad antes de buscar imágenes
            $encodedHtml = preg_replace('/<div[^>]+class="[^"]*(?:estac-entity-placement|estac-anuncio|advertisement|ad-container)[^"]*"[^>]*>.*?<\/div>\s*<\/div>/si', '', $encodedHtml);
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $encodedHtml, $matches)) {
                foreach ($matches[1] as $imgUrl) {
                    $imgUrl = html_entity_decode($imgUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (stripos($imgUrl, '.gif') === false && !$this->isAdImage($imgUrl)) return $imgUrl;
                }
            }
        }

        // 4º: primer <img> en la descripción del ítem (excluyendo anuncios)
        $rawDesc = (string)$item->description;
        $rawDesc = preg_replace('/<div[^>]+class="[^"]*(?:estac-entity-placement|estac-anuncio|advertisement|ad-container)[^"]*"[^>]*>.*?<\/div>\s*<\/div>/si', '', $rawDesc);
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $rawDesc, $matches)) {
            foreach ($matches[1] as $imgUrl) {
                $imgUrl = html_entity_decode($imgUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (stripos($imgUrl, '.gif') === false && !$this->isAdImage($imgUrl)) return $imgUrl;
            }
        }

        // 5º: og:image / twitter:image del artículo original (fallback)
        if ($link !== '') {
            $ogImage = $this->fetchOgImage($link);
            if ($ogImage !== null && stripos($ogImage, '.gif') === false) {
                return $ogImage;
            }
        }

        // Último recurso: placeholder único generado con Picsum
        $seed = substr(md5($link), 0, 8);
        return "https://picsum.photos/seed/{$seed}/600/400";
    }

    /**
     * Detecta si una URL de imagen corresponde a un banner publicitario.
     * Filtra patrones comunes de nombres de archivo de anuncios.
     */
    private function isAdImage(string $url): bool {
        $lower = strtolower(basename(parse_url($url, PHP_URL_PATH) ?? ''));
        $adPatterns = ['banner', 'pauta', 'publicidad', 'anuncio', 'sponsor', 'propaganda', 'aviso'];
        foreach ($adPatterns as $pattern) {
            if (str_contains($lower, $pattern)) return true;
        }
        return false;
    }

    /**
     * Scrapea una página HTML de noticias (para sitios sin RSS).
     * Detecta enlaces con patrón /noticia/ y extrae título, imagen y fecha.
     *
     * @param string $url        URL de la página de noticias
     * @param string $sourceName Nombre del medio
     * @return array             Lista de noticias extraídas
     */
    private function scrapeHtmlPage(string $url, string $sourceName): array {
        $ctx = stream_context_create([
            'http' => ['timeout' => 8, 'user_agent' => 'FunesNewsAgent/1.0'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false || $html === '') {
            $this->log("[ERROR] {$sourceName}: no se pudo descargar la página ({$url})");
            return [];
        }

        // Verificar código HTTP
        if (!empty($http_response_header)) {
            if (preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $hm) && (int)$hm[1] >= 400) {
                $this->log("[ERROR] {$sourceName}: página retornó HTTP {$hm[1]} ({$url})");
                return [];
            }
        }

        $this->log("[OK] {$sourceName}: página descargada correctamente");

        // Extraer base URL para resolver links relativos
        $parsed  = parse_url($url);
        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Recolectar todos los links de noticias con título no vacío
        // El sitio usa href SIN slash inicial: "noticia/slug/id"
        $byUrl = [];
        $links = $xpath->query('//a[contains(@href, "noticia/")]');

        foreach ($links as $a) {
            $href  = trim($a->getAttribute('href'));
            $title = trim($a->textContent);

            if (mb_strlen($title) < 10) continue; // links de imagen o sin texto

            // Resolver URL relativa a absoluta (con o sin slash inicial)
            if (str_starts_with($href, 'http')) {
                // ya es absoluta
            } elseif (str_starts_with($href, '/')) {
                $href = $baseUrl . $href;
            } else {
                $href = $baseUrl . '/' . $href;
            }

            if (isset($byUrl[$href])) continue; // ya tenemos este artículo con título
            $byUrl[$href] = $title;
        }

        if (empty($byUrl)) return [];

        // Para cada URL encontrada, buscar imagen y fecha en el HTML
        // Usamos regex sobre el HTML crudo para evitar problemas de estructura DOM
        $items = [];
        foreach ($byUrl as $link => $title) {
            // Buscar fecha cerca del link en el HTML crudo (formato YYYY-MM-DD HH:MM:SS)
            $pubDate  = date('Y-m-d H:i:s');
            $urlPath  = parse_url($link, PHP_URL_PATH) ?? '';
            // Buscar fecha en los 800 chars que rodean la URL del artículo
            $pos = $urlPath !== '' ? strpos($html, ltrim($urlPath, '/')) : false;
            if ($pos !== false) {
                $chunk = substr($html, max(0, $pos - 400), 800);
                if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $chunk, $dm)) {
                    $pubDate = $dm[1];
                }
            }

            // 1º: imagen más cercana al link en el HTML del listado
            $image = null;
            $pos   = strpos($html, parse_url($link, PHP_URL_PATH));
            if ($pos !== false) {
                $chunk = substr($html, max(0, $pos - 600), 1200);
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $chunk, $im)) {
                    $src = $im[1];
                    if (stripos($src, '.gif') === false) {
                        $image = str_starts_with($src, '/') ? $baseUrl . $src : $src;
                    }
                }
            }

            // 2º: og:image / twitter:image del artículo original (fallback)
            if ($image === null) {
                $ogImage = $this->fetchOgImage($link);
                if ($ogImage !== null && stripos($ogImage, '.gif') === false) {
                    $image = $ogImage;
                }
            }

            // Último recurso: placeholder Picsum
            if ($image === null) {
                $seed  = substr(md5($link), 0, 8);
                $image = "https://picsum.photos/seed/{$seed}/600/400";
            }

            $items[] = [
                'title'       => $title,
                'link'        => $link,
                'image_url'   => $image,
                'source'      => $sourceName,
                'pub_date'    => $pubDate,
                'description' => null
            ];

            if (count($items) >= 20) break;
        }

        return $items;
    }

    /**
     * Obtiene la imagen principal de una URL buscando los meta tags
     * og:image o twitter:image en el <head> de la página.
     * Solo descarga los primeros 15 KB para no procesar el body completo.
     *
     * @param string $url URL del artículo
     * @return string|null URL de la imagen, o null si no se encontró
     */
    private function fetchOgImage(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => 3,
                'user_agent'      => 'FunesNewsAgent/1.0',
                'follow_location' => 1,
                'max_redirects'   => 3,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false || $html === '') {
            return null;
        }

        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';

        // 1º: wp-post-image (imagen destacada de WordPress) — más confiable que og:image
        //     ya que siempre es la imagen del artículo, nunca el logo del sitio
        if (preg_match('/<img[^>]+class=["\'][^"\']*wp-post-image[^"\']*["\'][^>]+src=["\']([^"\']+)["\']/i', $html, $m) ||
            preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]+class=["\'][^"\']*wp-post-image[^"\']*["\']/i', $html, $m)) {
            $raw = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($raw !== '' && stripos($raw, '.gif') === false) {
                return $this->resolveImageUrl($raw, $scheme, $host);
            }
        }

        // 2º: og:image / twitter:image del <head>
        $head = substr($html, 0, 15000);
        $raw  = null;

        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $head, $m)) {
            $raw = trim($m[1]);
        } elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $head, $m)) {
            $raw = trim($m[1]);
        } elseif (preg_match('/<meta[^>]+(?:name|property)=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $head, $m)) {
            $raw = trim($m[1]);
        } elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\']twitter:image["\']/i', $head, $m)) {
            $raw = trim($m[1]);
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->resolveImageUrl($raw, $scheme, $host);
    }

    private function resolveImageUrl(string $raw, string $scheme, string $host): ?string
    {
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }
        if (str_starts_with($raw, '//')) {
            $withScheme = $scheme . ':' . $raw;
            $parsedImg  = parse_url($withScheme);
            if (isset($parsedImg['host']) && str_contains($parsedImg['host'], '.')) {
                return $withScheme;
            }
            return $scheme . '://' . $host . $raw;
        }
        if (str_starts_with($raw, '/')) {
            $stripped = ltrim($raw, '/');
            if (str_starts_with($stripped, 'http://') || str_starts_with($stripped, 'https://')) {
                return $stripped;
            }
            return $scheme . '://' . $host . $raw;
        }

        return null;
    }

    /**
     * Si un feed falla, no se insertan datos ficticios.
     * Devuelve array vacío para no contaminar el feed con contenido falso.
     */
    private function getMockData(string $sourceName): array {
        return [];
    }
}
