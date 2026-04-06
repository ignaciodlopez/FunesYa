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

        if (!empty($newsList)) {
            $newsList = $this->deduplicateItems($newsList);
            $this->db->saveNews($newsList);
        }

        $this->log('Total artículos procesados: ' . count($newsList));
        
        $this->db->setLastUpdate(time());
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
        if (!$rssContent) {
            $this->log("[ERROR] {$sourceName}: no se pudo descargar el feed ({$url})");
            return $this->getMockData($sourceName);
        }

        try {
            $xml = simplexml_load_string($rssContent);
            if ($xml && isset($xml->channel->item)) {
                $this->log("[OK] {$sourceName}: feed descargado correctamente");
                foreach ($xml->channel->item as $item) {
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
                        'title' => trim((string)$item->title),
                        'link' => trim((string)$item->link),
                        'image_url' => $image,
                        'source' => $sourceName,
                        'pub_date' => date('Y-m-d H:i:s', $pubDateTimestamp),
                        'description' => $description ?: null
                    ];
                }
            } else {
                $this->log("[WARN] {$sourceName}: feed inválido o sin ítems");
                return $this->getMockData($sourceName);
            }
        } catch (Exception $e) {
            $this->log("[ERROR] {$sourceName}: excepción al parsear — " . $e->getMessage());
            return $this->getMockData($sourceName);
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
                    $url = (string)$enc['url'];
                    if (stripos($url, '.gif') === false) return $url;
                }
            }
        }

        // 2º: media:content
        $media = $item->children('media', true);
        if (isset($media->content)) {
            $url = (string)$media->content->attributes()->url;
            if ($url !== '' && stripos($url, '.gif') === false) return $url;
        }

        // 3º: primer <img> en content:encoded
        $content = $item->children('content', true);
        if (isset($content->encoded)) {
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', (string)$content->encoded, $matches)) {
                foreach ($matches[1] as $imgUrl) {
                    $imgUrl = html_entity_decode($imgUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (stripos($imgUrl, '.gif') === false) return $imgUrl;
                }
            }
        }

        // 4º: primer <img> en la descripción del ítem
        $rawDesc = (string)$item->description;
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $rawDesc, $matches)) {
            foreach ($matches[1] as $imgUrl) {
                $imgUrl = html_entity_decode($imgUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (stripos($imgUrl, '.gif') === false) return $imgUrl;
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
        if (!$html) {
            $this->log("[ERROR] {$sourceName}: no se pudo descargar la página ({$url})");
            return $this->getMockData($sourceName);
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

        if (empty($byUrl)) return $this->getMockData($sourceName);

        // Para cada URL encontrada, buscar imagen y fecha en el HTML
        // Usamos regex sobre el HTML crudo para evitar problemas de estructura DOM
        $items = [];
        foreach ($byUrl as $link => $title) {
            // Buscar fecha cerca del link en el HTML crudo (formato YYYY-MM-DD HH:MM:SS)
            $pubDate  = date('Y-m-d H:i:s');
            $urlPath  = parse_url($link, PHP_URL_PATH);
            $pattern  = preg_quote(ltrim($urlPath, '/'), '/');
            // Buscar fecha en los 800 chars que rodean la URL del artículo
            $pos = strpos($html, ltrim($urlPath, '/'));
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

        return !empty($items) ? $items : $this->getMockData($sourceName);
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
                'timeout'         => 5,
                'user_agent'      => 'FunesNewsAgent/1.0',
                'follow_location' => 1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false) {
            return null;
        }

        // Solo procesar el <head> (primeros 15 KB) para mayor eficiencia
        $head = substr($html, 0, 15000);

        $raw = null;

        // og:image — property antes de content
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $head, $m)) {
            $raw = trim($m[1]);
        }
        // og:image — content antes de property
        elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $head, $m)) {
            $raw = trim($m[1]);
        }
        // twitter:image — name/property antes de content
        elseif (preg_match('/<meta[^>]+(?:name|property)=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $head, $m)) {
            $raw = trim($m[1]);
        }
        // twitter:image — content antes de name/property
        elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\']twitter:image["\']/i', $head, $m)) {
            $raw = trim($m[1]);
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        // Resolver URLs relativas contra la URL base del artículo
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            // URL absoluta: usar tal cual
            return $raw;
        }
        if (str_starts_with($raw, '//')) {
            // Protocol-relative: verificar que el host sea real (tiene punto)
            $withScheme = $scheme . ':' . $raw;
            $parsedImg  = parse_url($withScheme);
            if (isset($parsedImg['host']) && str_contains($parsedImg['host'], '.')) {
                return $withScheme;
            }
            // Si no tiene host real, tratar como ruta absoluta
            return $scheme . '://' . $host . $raw;
        }
        if (str_starts_with($raw, '/')) {
            // Algunos sitios mal­forman el og:image con una / inicial antes de https://
            // Ejemplo: "/https://cdn.example.com/img.jpg"
            $stripped = ltrim($raw, '/');
            if (str_starts_with($stripped, 'http://') || str_starts_with($stripped, 'https://')) {
                return $stripped;
            }
            // Ruta absoluta estándar: añadir esquema + host
            return $scheme . '://' . $host . $raw;
        }

        // Ruta relativa sin / inicial: demasiado ambigua, descartar
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
