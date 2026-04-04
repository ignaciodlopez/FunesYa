<?php
declare(strict_types=1);

/**
 * Agrega noticias desde múltiples fuentes RSS locales de Funes.
 * Genera datos de ejemplo (mock) cuando un feed no está disponible.
 */
class Aggregator {
    private Database $db;

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
    }

    /**
     * Recorre todos los feeds configurados, parsea sus ítems y los persiste en la base de datos.
     * Actualiza la marca de tiempo de la última sincronización.
     */
    public function fetchAll(): void {
        $newsList = [];
        
        foreach ($this->feeds as $name => $url) {
            $feedItems = $this->parseFeed($url, $name);
            $newsList = array_merge($newsList, $feedItems);
        }

        foreach ($this->scrapers as $name => $url) {
            $scrapedItems = $this->scrapeHtmlPage($url, $name);
            $newsList = array_merge($newsList, $scrapedItems);
        }
        
        if (!empty($newsList)) {
            $this->db->saveNews($newsList);
        }
        
        $this->db->setLastUpdate(time());
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
            return $this->getMockData($sourceName);
        }

        try {
            $xml = simplexml_load_string($rssContent);
            if ($xml && isset($xml->channel->item)) {
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
                return $this->getMockData($sourceName);
            }
        } catch (Exception $e) {
            return $this->getMockData($sourceName);
        }

        return $items;
    }

    /**
     * Extrae la URL de la imagen principal de un ítem RSS.
     * Busca en: enclosure, media:content, content:encoded y description.
     * Si no encuentra ninguna, genera un placeholder único con Picsum basado en la URL del artículo.
     *
     * @param SimpleXMLElement $item Ítem del feed RSS
     * @return string                URL de la imagen o del placeholder
     */
    private function extractImage(SimpleXMLElement $item): string {
        if (isset($item->enclosure)) {
            foreach ($item->enclosure as $enc) {
                $type = (string)$enc['type'];
                if (strpos($type, 'image/') !== false) {
                    $url = (string)$enc['url'];
                    if (strpos(strtolower($url), '.gif') === false) return $url;
                }
            }
        }
        
        $media = $item->children('media', true);
        if (isset($media->content)) {
            $url = (string)$media->content->attributes()->url;
            if (strpos(strtolower($url), '.gif') === false) return $url;
        }

        // Busca imagen válida en el campo content:encoded
        $content = $item->children('content', true);
        if (isset($content->encoded)) {
             if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', (string)$content->encoded, $matches)) {
                 foreach ($matches[1] as $imgUrl) {
                     if (strpos(strtolower($imgUrl), '.gif') === false) return $imgUrl;
                 }
             }
        }

        // Busca imagen válida en la descripción del ítem
        $description = (string)$item->description;
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $matches)) {
            foreach ($matches[1] as $imgUrl) {
                if (strpos(strtolower($imgUrl), '.gif') === false) return $imgUrl;
            }
        }

        // Fallback: placeholder único generado con Picsum, semillado por URL para mayor variedad
        $link = trim((string)$item->link);
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
        if (!$html) return $this->getMockData($sourceName);

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

            // Buscar imagen más cercana al link
            $image = null;
            $pos   = strpos($html, parse_url($link, PHP_URL_PATH));
            if ($pos !== false) {
                $chunk = substr($html, max(0, $pos - 600), 1200);
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $chunk, $im)) {
                    $src = $im[1];
                    if (strpos(strtolower($src), '.gif') === false) {
                        $image = str_starts_with($src, '/') ? $baseUrl . $src : $src;
                    }
                }
            }

            if (!$image) {
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
     * Si un feed falla, no se insertan datos ficticios.
     * Devuelve array vacío para no contaminar el feed con contenido falso.
     */
    private function getMockData(string $sourceName): array {
        return [];
    }
}
