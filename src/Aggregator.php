<?php
declare(strict_types=1);

/**
 * Agrega noticias desde múltiples fuentes RSS locales de Funes.
 * Genera datos de ejemplo (mock) cuando un feed no está disponible.
 */
class Aggregator
{
    private Database $db;
    private string $logFile;
    private string $statusFile;
    private array $cycleStatus = [];

    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** @var array<string, array{etag: string, last_modified: string}> Cabeceras de caché por URL de feed */
    private array $feedCacheHeaders = [];

    private const FEED_CACHE_FILE = __DIR__ . '/../data/feed_cache_headers.json';

    private function loadFeedCacheHeaders(): void {
        if (file_exists(self::FEED_CACHE_FILE)) {
            $data = json_decode(file_get_contents(self::FEED_CACHE_FILE), true);
            $this->feedCacheHeaders = is_array($data) ? $data : [];
        }
    }

    private function saveFeedCacheHeaders(): void {
        file_put_contents(
            self::FEED_CACHE_FILE,
            json_encode($this->feedCacheHeaders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

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
        $this->statusFile = __DIR__ . '/../data/aggregator_status.json';
    }

    /**
     * Recorre todos los feeds configurados, parsea sus ítems y los persiste en la base de datos.
     * Actualiza la marca de tiempo de la última sincronización.
     */
    public function fetchAll(): void {
        $newsList = [];
        $this->cycleStatus = [];
        $this->log('--- Inicio ciclo de actualización ---');
        $this->loadFeedCacheHeaders();

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
            $newsList = $this->repairIncomingImages($newsList);
            $newsList = $this->removeSiteWideImages($newsList);
            $readyToSaveBySource = [];
            foreach ($newsList as $item) {
                $readyToSaveBySource[$item['source']] = ($readyToSaveBySource[$item['source']] ?? 0) + 1;
            }
            foreach ($readyToSaveBySource as $source => $count) {
                $this->cycleStatus[$source]['ready_to_save'] = $count;
            }
            $saved = $this->db->saveNews($newsList);
        }

        $this->log('Procesados: ' . count($newsList) . ', nuevos guardados: ' . $saved);
        foreach ($this->cycleStatus as $source => $status) {
            $this->log(sprintf(
                '[SOURCE] %s | estado=%s | obtenidos=%d | listos=%d | detalle=%s',
                $source,
                $status['state'] ?? 'unknown',
                (int)($status['items_fetched'] ?? 0),
                (int)($status['ready_to_save'] ?? 0),
                $status['message'] ?? 'sin detalle'
            ));
        }

        $this->writeStatus([
            'generated_at' => date(DATE_ATOM),
            'saved' => $saved,
            'processed' => count($newsList),
            'sources' => $this->cycleStatus,
        ]);
        
        $this->saveFeedCacheHeaders();
        $this->db->setLastUpdate(time());
    }

    private function setSourceStatus(string $sourceName, array $status): void {
        $this->cycleStatus[$sourceName] = array_merge([
            'state' => 'unknown',
            'type' => 'feed',
            'url' => null,
            'items_fetched' => 0,
            'ready_to_save' => 0,
            'latest_pub_date' => null,
            'message' => null,
        ], $status);
    }

    private function writeStatus(array $payload): void {
        file_put_contents(
            $this->statusFile,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Segunda pasada de autocorrección para imágenes faltantes, rotas o genéricas.
     * Se ejecuta dentro del mismo ciclo del agregador, así no hace falta correr
     * scripts manuales para las noticias nuevas que recién ingresan.
     */
    private function repairIncomingImages(array $items): array {
        foreach ($items as &$item) {
            $url = trim((string)($item['image_url'] ?? ''));
            if (!$this->needsImageRepair($url)) {
                continue;
            }

            $repaired = $this->fetchOgImage((string)$item['link']);
            $item['image_url'] = ($repaired !== null && $this->isUsableImage($repaired))
                ? $repaired
                : '';
        }
        unset($item);

        return $items;
    }

    private function needsImageRepair(string $url): bool {
        $url = trim($url);
        return $url === ''
            || $this->isStockImage($url)
            || $this->isMalformedImageUrl($url)
            || $this->isLikelyGenericSiteImage($url);
    }

    private function isMalformedImageUrl(string $url): bool {
        return preg_match('#^https?://[^/]+/https?://#i', $url) === 1;
    }

    /**
     * Limpia únicamente imágenes claramente genéricas del sitio.
     * Importante: NO se eliminan fotos periodísticas reales solo por repetirse
     * en notas relacionadas, porque eso genera falsos positivos.
     */
    private function removeSiteWideImages(array $items): array {
        $freq = [];
        foreach ($items as $item) {
            $url = trim((string)($item['image_url'] ?? ''));
            if ($url === '' || $this->isStockImage($url)) {
                continue;
            }
            $freq[$item['source']][$url] = ($freq[$item['source']][$url] ?? 0) + 1;
        }

        foreach ($items as &$item) {
            $url = trim((string)($item['image_url'] ?? ''));
            if ($url === '' || $this->isStockImage($url)) {
                continue;
            }

            $occurrences = (int)($freq[$item['source']][$url] ?? 0);
            if ($occurrences >= 4 && $this->isLikelyGenericSiteImage($url)) {
                $item['image_url'] = '';
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
     * Descarga y parsea un feed RSS o Atom. Soporta ETag/Last-Modified para evitar
     * reprocesar feeds sin cambios. Reintenta una vez ante fallos de red.
     *
     * @param string $url        URL del feed
     * @param string $sourceName Nombre del medio de comunicación
     * @return array             Lista de noticias con título, link, imagen, fuente y fecha
     */
    private function parseFeed(string $url, string $sourceName): array {
        $items = [];

        // Construir cabeceras condicionales si tenemos ETag/Last-Modified previos
        $conditionalHeaders = [];
        if (!empty($this->feedCacheHeaders[$url]['etag'])) {
            $conditionalHeaders[] = 'If-None-Match: ' . $this->feedCacheHeaders[$url]['etag'];
        }
        if (!empty($this->feedCacheHeaders[$url]['last_modified'])) {
            $conditionalHeaders[] = 'If-Modified-Since: ' . $this->feedCacheHeaders[$url]['last_modified'];
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 5,
                'user_agent' => self::USER_AGENT,
                'header'     => implode("\r\n", $conditionalHeaders),
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        // Reintento: 2 intentos con 1 s de pausa entre ellos
        $rssContent = false;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $rssContent = @file_get_contents($url, false, $ctx);
            if ($rssContent !== false) break;
            if ($attempt < 2) sleep(1);
        }

        if ($rssContent === false || $rssContent === '') {
            $this->log("[ERROR] {$sourceName}: no se pudo descargar el feed tras 2 intentos ({$url})");
            $this->setSourceStatus($sourceName, [
                'state' => 'error',
                'type' => 'feed',
                'url' => $url,
                'message' => 'No se pudo descargar el feed tras 2 intentos',
            ]);
            return [];
        }

        // Verificar código HTTP — 304 = sin cambios, devolver vacío sin reprocesar
        if (!empty($http_response_header)) {
            if (preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $hm)) {
                $statusCode = (int)$hm[1];
                if ($statusCode === 304) {
                    $this->log("[SKIP] {$sourceName}: feed sin cambios (304)");
                    $this->setSourceStatus($sourceName, [
                        'state' => 'ok',
                        'type' => 'feed',
                        'url' => $url,
                        'items_fetched' => 0,
                        'message' => 'Sin cambios desde el último ciclo (304)',
                    ]);
                    return [];
                }
                if ($statusCode >= 400) {
                    $this->log("[ERROR] {$sourceName}: feed retornó HTTP {$statusCode} ({$url})");
                    $this->setSourceStatus($sourceName, [
                        'state' => 'error',
                        'type' => 'feed',
                        'url' => $url,
                        'message' => 'HTTP ' . $statusCode,
                    ]);
                    return [];
                }
            }

            // Guardar ETag y Last-Modified para el próximo ciclo
            foreach ($http_response_header as $h) {
                if (stripos($h, 'ETag:') === 0) {
                    $this->feedCacheHeaders[$url]['etag'] = trim(substr($h, 5));
                }
                if (stripos($h, 'Last-Modified:') === 0) {
                    $this->feedCacheHeaders[$url]['last_modified'] = trim(substr($h, 14));
                }
            }
        }

        libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($rssContent);
            libxml_clear_errors();

            if (!$xml) {
                $this->log("[WARN] {$sourceName}: feed inválido o sin ítems");
                $this->setSourceStatus($sourceName, [
                    'state' => 'warn',
                    'type' => 'feed',
                    'url' => $url,
                    'message' => 'Feed inválido o sin ítems',
                ]);
                return [];
            }

            // Detectar formato: RSS (<channel><item>) o Atom (<feed><entry>)
            $rootName = $xml->getName();
            $isAtom = ($rootName === 'feed');

            if ($isAtom) {
                $items = $this->parseAtomFeed($xml, $sourceName, $url);
            } elseif (isset($xml->channel->item)) {
                $items = $this->parseRssFeed($xml, $sourceName, $url);
            } else {
                $this->log("[WARN] {$sourceName}: feed inválido o sin ítems");
                $this->setSourceStatus($sourceName, [
                    'state' => 'warn',
                    'type' => 'feed',
                    'url' => $url,
                    'message' => 'Feed inválido o sin ítems',
                ]);
                return [];
            }
        } catch (Exception $e) {
            libxml_clear_errors();
            $this->log("[ERROR] {$sourceName}: excepción al parsear — " . $e->getMessage());
            $this->setSourceStatus($sourceName, [
                'state' => 'error',
                'type' => 'feed',
                'url' => $url,
                'message' => 'Excepción al parsear: ' . $e->getMessage(),
            ]);
            return [];
        }

        return $items;
    }

    /** Parsea un feed RSS 2.0 y devuelve los ítems normalizados. */
    private function parseRssFeed(SimpleXMLElement $xml, string $sourceName, string $url): array {
        $items = [];
        foreach ($xml->channel->item as $item) {
            $title = trim(html_entity_decode((string)$item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $link  = trim(html_entity_decode((string)$item->link,  ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($title === '' || $link === '' || !str_starts_with($link, 'http')) continue;

            $image = $this->extractImage($item);
            $pubDateTimestamp = strtotime((string)$item->pubDate) ?: time();

            $rawDesc = (string)$item->description;
            $description = trim(html_entity_decode(strip_tags($rawDesc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (mb_strlen($description, 'UTF-8') > 400) {
                $description = mb_substr($description, 0, 397, 'UTF-8') . '...';
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

        $this->log("[OK] {$sourceName}: " . count($items) . " artículos obtenidos (RSS)");
        $this->setSourceStatus($sourceName, [
            'state' => 'ok',
            'type' => 'feed',
            'url' => $url,
            'items_fetched' => count($items),
            'latest_pub_date' => $this->latestPubDate($items),
            'message' => 'Feed RSS procesado correctamente',
        ]);

        return $items;
    }

    /** Parsea un feed Atom 1.0 y devuelve los ítems normalizados. */
    private function parseAtomFeed(SimpleXMLElement $xml, string $sourceName, string $url): array {
        $items = [];
        $ns = $xml->getNamespaces(true);

        foreach ($xml->entry as $entry) {
            $title = trim(html_entity_decode((string)$entry->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            // <link rel="alternate" href="..."> o el primer <link href="...">
            $link = '';
            foreach ($entry->link as $l) {
                $rel  = (string)$l['rel'];
                $href = trim((string)$l['href']);
                if ($href !== '' && ($rel === 'alternate' || $rel === '')) {
                    $link = $href;
                    break;
                }
            }
            if ($link === '' && isset($entry->link['href'])) {
                $link = trim((string)$entry->link['href']);
            }

            if ($title === '' || $link === '' || !str_starts_with($link, 'http')) continue;

            // Fecha: <updated> o <published>
            $dateStr = (string)($entry->updated ?? $entry->published ?? '');
            $pubDateTimestamp = strtotime($dateStr) ?: time();

            // Descripción desde <summary> o <content>
            $rawDesc = (string)($entry->summary ?? $entry->content ?? '');
            $description = trim(html_entity_decode(strip_tags($rawDesc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (mb_strlen($description, 'UTF-8') > 400) {
                $description = mb_substr($description, 0, 397, 'UTF-8') . '...';
            }

            // Imagen: intentar media:thumbnail, media:content, og:image de la página
            $image = '';
            foreach ($ns as $prefix => $nsUri) {
                if (str_contains($nsUri, 'media.')) {
                    $media = $entry->children($nsUri);
                    if (isset($media->thumbnail)) {
                        $candidate = trim((string)$media->thumbnail['url']);
                        if ($candidate !== '' && str_starts_with($candidate, 'http')) {
                            $image = $candidate;
                            break;
                        }
                    }
                    if (isset($media->content)) {
                        $candidate = trim((string)$media->content['url']);
                        if ($candidate !== '' && str_starts_with($candidate, 'http')) {
                            $image = $candidate;
                            break;
                        }
                    }
                }
            }
            if ($image === '') {
                $og = $this->fetchOgImage($link);
                $image = $og ?? '';
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

        $this->log("[OK] {$sourceName}: " . count($items) . " artículos obtenidos (Atom)");
        $this->setSourceStatus($sourceName, [
            'state' => 'ok',
            'type' => 'feed',
            'url' => $url,
            'items_fetched' => count($items),
            'latest_pub_date' => $this->latestPubDate($items),
            'message' => 'Feed Atom procesado correctamente',
        ]);

        return $items;
    }

    private function latestPubDate(array $items): ?string {
        $latest = null;
        foreach ($items as $entry) {
            if ($latest === null || $entry['pub_date'] > $latest) {
                $latest = $entry['pub_date'];
            }
        }
        return $latest;
    }

    /**
     * Extrae la URL de la imagen principal de un ítem RSS.
     * Prioridad: enclosure > media:content > content:encoded > description >
     *            og:image de la página original.
     * Se usa og:image como fallback (no como primera opción) para evitar
     * una petición HTTP extra cuando el RSS ya embedía la imagen correcta.
     *
     * @param SimpleXMLElement $item Ítem del feed RSS
     * @return string                URL de la imagen o cadena vacía si no se encontró
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

        // 3º: primer <img> en content:encoded (excluyendo anuncios e imágenes retrato)
        $content = $item->children('content', true);
        if (isset($content->encoded)) {
            $encodedHtml = (string)$content->encoded;
            $encodedHtml = preg_replace('/<div[^>]+class="[^"]*(?:estac-entity-placement|estac-anuncio|advertisement|ad-container)[^"]*"[^>]*>.*?<\/div>\s*<\/div>/si', '', $encodedHtml);
            if (preg_match_all('/<img((?:[^>](?!\/>))*.)>/i', $encodedHtml, $tags)) {
                foreach ($tags[0] as $tag) {
                    $imgUrl = $this->extractImageCandidateFromTag($tag);
                    if ($imgUrl === null) continue;
                    preg_match('/width=["\']?(\d+)/i', $tag, $wm);
                    preg_match('/height=["\']?(\d+)/i', $tag, $hm);
                    $w = (int)($wm[1] ?? 0);
                    $h = (int)($hm[1] ?? 0);
                    $imgUrl = $this->normalizeImageUrl($imgUrl);
                    if ($this->isUsableImage($imgUrl, $w, $h)) return $imgUrl;
                }
            }
        }

        // 4º: primer <img> en la descripción del ítem (excluyendo anuncios e imágenes retrato)
        $rawDesc = (string)$item->description;
        $rawDesc = preg_replace('/<div[^>]+class="[^"]*(?:estac-entity-placement|estac-anuncio|advertisement|ad-container)[^"]*"[^>]*>.*?<\/div>\s*<\/div>/si', '', $rawDesc);
        if (preg_match_all('/<img((?:[^>](?!\/>))*.)>/i', $rawDesc, $tags)) {
            foreach ($tags[0] as $tag) {
                $imgUrl = $this->extractImageCandidateFromTag($tag);
                if ($imgUrl === null) continue;
                preg_match('/width=["\']?(\d+)/i', $tag, $wm);
                preg_match('/height=["\']?(\d+)/i', $tag, $hm);
                $w = (int)($wm[1] ?? 0);
                $h = (int)($hm[1] ?? 0);
                $imgUrl = $this->normalizeImageUrl($imgUrl);
                if ($this->isUsableImage($imgUrl, $w, $h)) return $imgUrl;
            }
        }

        // 5º: og:image / twitter:image del artículo original (fallback)
        if ($link !== '') {
            $ogImage = $this->fetchOgImage($link);
            if ($ogImage !== null && stripos($ogImage, '.gif') === false) {
                return $ogImage;
            }
        }

        // Si no hay una imagen real usable, dejar vacío y que la UI no muestre stock.
        return '';
    }

    /**
     * Determina si una imagen es usable como portada de noticia.
     * Descarta: GIFs, CDNs de emojis, imágenes retrato (alto > ancho),
     * imágenes muy pequeñas, y banners publicitarios por nombre de archivo.
     */
    private function isUsableImage(string $url, int $w = 0, int $h = 0): bool {
        if (!str_starts_with($url, 'http')) return false;
        if (stripos($url, '.gif') !== false) return false;
        if ($this->isAdImage($url)) return false;
        if ($this->isStockImage($url)) return false;
        if ($this->isLikelyGenericSiteImage($url)) return false;

        // CDNs de emojis / íconos externos
        $badDomains = ['fbcdn.net', 'emoji.php', 'twimg.com/emoji', 's.w.org/images/core/emoji'];
        foreach ($badDomains as $d) {
            if (str_contains($url, $d)) return false;
        }

        // Si el HTML informó dimensiones: descartar si es retrato o demasiado pequeña
        if ($w > 0 && $h > 0) {
            if ($h > $w) return false;   // retrato
            if ($w < 100 || $h < 60) return false; // ícono
        }

        // Detectar tamaño retrato desde el sufijo de URL WordPress (-251x300)
        if (preg_match('/-(?P<w>\d+)x(?P<h>\d+)\.(?:jpg|jpeg|png|webp)$/i', $url, $sz)) {
            if ((int)$sz['h'] > (int)$sz['w']) return false;
        }

        return true;
    }

    /**
     * Detecta imágenes de stock/placeholder que no deben mostrarse al usuario.
     */
    private function isStockImage(string $url): bool {
        $url = strtolower(trim($url));
        return $url !== ''
            && (str_contains($url, 'picsum.photos/') || str_contains($url, 'images.unsplash.com/'));
    }

    /**
     * Detecta logos, avatares o imágenes institucionales genéricas del sitio.
     */
    private function isLikelyGenericSiteImage(string $url): bool {
        $lower = strtolower($url);
        $patterns = [
            'logo', 'favicon', 'avatar', 'placeholder', 'default', 'no-image',
            'sin-imagen', 'site-share', 'share-default', 'og-default', 'brand'
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return str_contains($lower, 'flex-app.tadevel-cdn.com/hostname/');
    }

    /**
     * Detecta si una URL de imagen corresponde a un banner publicitario.
     * Filtra patrones comunes de nombres de archivo de anuncios.
     */
    private function isAdImage(string $url): bool {
        $lower = strtolower(basename(parse_url($url, PHP_URL_PATH) ?? ''));
        $adPatterns = ['banner', 'pauta', 'publicidad', 'anuncio', 'sponsor', 'propaganda', 'aviso', 'ads', 'promo'];
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
            'http' => ['timeout' => 8, 'user_agent' => self::USER_AGENT],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false || $html === '') {
            $this->log("[ERROR] {$sourceName}: no se pudo descargar la página ({$url})");
            $this->setSourceStatus($sourceName, [
                'state' => 'error',
                'type' => 'scraper',
                'url' => $url,
                'message' => 'No se pudo descargar la página',
            ]);
            return [];
        }

        // Verificar código HTTP
        if (!empty($http_response_header)) {
            if (preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $hm) && (int)$hm[1] >= 400) {
                $this->log("[ERROR] {$sourceName}: página retornó HTTP {$hm[1]} ({$url})");
                $this->setSourceStatus($sourceName, [
                    'state' => 'error',
                    'type' => 'scraper',
                    'url' => $url,
                    'message' => 'HTTP ' . $hm[1],
                ]);
                return [];
            }
        }

        $this->log("[OK] {$sourceName}: página descargada correctamente");

        // Extraer base URL para resolver links relativos
        $parsed  = parse_url($url);
        $scheme  = $parsed['scheme'] ?? 'https';
        $host    = $parsed['host'] ?? '';
        $baseUrl = $scheme . '://' . $host;

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

        if (empty($byUrl)) {
            $this->setSourceStatus($sourceName, [
                'state' => 'warn',
                'type' => 'scraper',
                'url' => $url,
                'message' => 'No se detectaron enlaces de noticias',
            ]);
            return [];
        }

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
                if (preg_match_all('/<img\b[^>]*>/i', $chunk, $imgTags)) {
                    foreach ($imgTags[0] as $tag) {
                        $src = $this->extractImageCandidateFromTag($tag);
                        if ($src === null || stripos($src, '.gif') !== false) {
                            continue;
                        }
                        $resolved = $this->resolveImageUrl($src, $scheme, $host);
                        if ($resolved !== null) {
                            $resolved = $this->normalizeImageUrl($resolved);
                        }
                        if ($resolved !== null && $this->isUsableImage($resolved)) {
                            $image = $resolved;
                            break;
                        }
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

            $items[] = [
                'title'       => $title,
                'link'        => $link,
                'image_url'   => $image ?? '',
                'source'      => $sourceName,
                'pub_date'    => $pubDate,
                'description' => null
            ];

            if (count($items) >= 20) break;
        }

        $latestPubDate = null;
        foreach ($items as $entry) {
            if ($latestPubDate === null || $entry['pub_date'] > $latestPubDate) {
                $latestPubDate = $entry['pub_date'];
            }
        }

        $this->setSourceStatus($sourceName, [
            'state' => 'ok',
            'type' => 'scraper',
            'url' => $url,
            'items_fetched' => count($items),
            'latest_pub_date' => $latestPubDate,
            'message' => 'Página scrapeada correctamente',
        ]);

        return $items;
    }

    /**
     * Obtiene la imagen principal de una URL buscando los meta tags
     * og:image o twitter:image en el <head> de la página.
     * Solo descarga los primeros 30 KB para no procesar el body completo.
     *
     * @param string $url URL del artículo
     * @return string|null URL de la imagen, o null si no se encontró
     */
    private function fetchOgImage(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => 8,
                'user_agent'      => self::USER_AGENT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'header'          => "Accept: text/html,*/*\r\nAccept-Language: es-AR,es;q=0.9\r\nRange: bytes=0-30719\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            return null;
        }
        $html = '';
        while (!feof($fp) && strlen($html) < 30720) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) break;
            $html .= $chunk;
        }
        fclose($fp);

        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';

        $candidates = [];

        // 1º: imágenes destacadas / hero en el HTML
        if (preg_match_all('/<img\b[^>]*class=["\'][^"\']*(?:wp-post-image|featured|hero|article|post-image|entry-image)[^"\']*["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $candidate = $this->extractImageCandidateFromTag($tag);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        // 2º: og:image / twitter:image del <head>
        $head = substr($html, 0, 30000);
        $metaPatterns = [
            '/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::secure_url)?["\']/i',
            '/<meta[^>]+(?:name|property)=["\']twitter:image(?::src)?["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\']twitter:image(?::src)?["\']/i',
        ];
        foreach ($metaPatterns as $pattern) {
            if (preg_match_all($pattern, $head, $matches)) {
                foreach ($matches[1] as $raw) {
                    $raw = trim($raw);
                    if ($raw !== '') {
                        $candidates[] = $raw;
                    }
                }
            }
        }

        // 3º: JSON-LD con campo image
        if (preg_match_all('/"image"\s*:\s*"([^"]+)"/i', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $raw = trim($raw);
                if ($raw !== '') {
                    $candidates[] = $raw;
                }
            }
        }

        // 4º: fallback final sobre todas las imágenes del documento (soporta lazy-loading)
        if (preg_match_all('/<img\b[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $candidate = $this->extractImageCandidateFromTag($tag);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        // 5º: URLs de imagen embebidas en JSON o scripts del HTML (útil para CDNs modernos)
        if (preg_match_all('~https?://[^"\'\s<>]+\.(?:avif|webp|png|jpe?g)(?:\?[^"\'\s<>]*)?~i', $html, $matches)) {
            foreach ($matches[0] as $raw) {
                $raw = trim($raw);
                if ($raw !== '') {
                    $candidates[] = $raw;
                }
            }
        }

        foreach (array_unique($candidates) as $raw) {
            $resolved = $this->resolveImageUrl($raw, $scheme, $host);
            if ($resolved === null) {
                continue;
            }

            $resolved = $this->normalizeImageUrl($resolved);
            if ($this->isUsableImage($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    private function extractImageCandidateFromTag(string $tag): ?string
    {
        $attributes = ['data-lazy-srcset', 'data-srcset', 'srcset', 'data-lazy-src', 'data-src', 'data-original', 'src'];

        foreach ($attributes as $attr) {
            if (!preg_match('/' . preg_quote($attr, '/') . '=["\']([^"\']+)["\']/i', $tag, $m)) {
                continue;
            }

            $raw = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($raw === '' || str_starts_with($raw, 'data:image/')) {
                continue;
            }

            if (str_contains($attr, 'srcset')) {
                $raw = $this->pickBestSrcsetUrl($raw) ?? '';
            }

            if ($raw !== '') {
                return $raw;
            }
        }

        return null;
    }

    private function pickBestSrcsetUrl(string $srcset): ?string
    {
        $bestUrl = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $candidate);
            $url = trim((string)($parts[0] ?? ''));
            $descriptor = strtolower((string)($parts[1] ?? ''));
            $width = (preg_match('/(\d+)w/', $descriptor, $m) === 1) ? (int)$m[1] : 0;

            if ($url !== '' && $width >= $bestWidth) {
                $bestUrl = $url;
                $bestWidth = $width;
            }
        }

        return $bestUrl;
    }

    private function normalizeImageUrl(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/\s+/', '%20', $url) ?? $url;

        // En CDNs como Tadevel/Flex conviene priorizar una variante más grande
        // para evitar miniaturas de 180/360 px cuando existe la de 720 px.
        $url = preg_replace(
            '~/(180|360|540)\.(avif|webp|png|jpe?g)(\?.*)?$~i',
            '/720.$2$3',
            $url
        ) ?? $url;

        return $url;
    }

    private function resolveImageUrl(string $raw, string $scheme, string $host): ?string
    {
        $raw = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($raw === '' || $host === '') {
            return null;
        }
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
            return $scheme . '://' . $host . '/' . $stripped;
        }

        return $scheme . '://' . $host . '/' . ltrim($raw, '/');
    }

    /**
     * Si un feed falla, no se insertan datos ficticios.
     * Devuelve array vacío para no contaminar el feed con contenido falso.
     */
    private function getMockData(string $sourceName): array {
        return [];
    }
}
