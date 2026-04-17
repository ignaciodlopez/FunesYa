<?php

declare(strict_types=1);

/**
 * Genera resúmenes de artículos de noticias.
 *
 * Flujo:
 *  1. Scrapea el HTML de la URL del artículo.
 *  2. Extrae párrafos de texto limpio.
 *  3. Llama a la API de Google Gemini para generar un resumen en español.
 *  4. Persiste el resultado en la base de datos para evitar llamadas repetidas.
 */
class ArticleSummarizer
{
    private const HTTP_TIMEOUT    = 10;
    private const GEMINI_TIMEOUT  = 15;
    private const MIN_PARA_LENGTH = 60;
    private const MAX_PARAGRAPHS  = 8;
    private const MAX_TEXT_CHARS  = 4000;   // ~1 000 tokens; evita derrochar cuota de Gemini
    private const SCRAPE_MAX_BYTES = 51200; // 50 KB: suficiente para extraer párrafos del artículo
    private const GEMINI_MIN_INTERVAL_MS = 350; // Mínimo entre llamadas a Gemini (ms)
    private const GEMINI_ENDPOINT =
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

    private const SCRAPE_USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const NOISE_TAGS = [
        'script', 'style', 'nav', 'footer',
        'aside', 'form', 'header', 'figure', 'figcaption',
        'noscript', 'iframe',
    ];

    public function __construct(private readonly Database $db) {}

    /**
     * Devuelve el resumen del artículo.
     * Usa el guardado en DB si existe; de lo contrario genera uno nuevo.
     *
     * @param array{id: int, link: string, description: ?string} $article
     */
    public function getSummary(array $article): ?string
    {
        if (!empty($article['description'])) {
            return $article['description'];
        }

        // Validar esquema antes de scrapear (defensa en profundidad contra SSRF)
        $scheme = strtolower(parse_url($article['link'], PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (str_starts_with($article['link'], 'https://example.com')) {
            return null;
        }

        $text = $this->scrapeText($article['link']);
        if ($text === null) {
            return null;
        }

        $summary = $this->callGemini($text)
            ?? $this->fallbackSummary($text);

        if ($summary !== null) {
            $this->db->saveSummary($article['id'], $summary);
        }

        return $summary;
    }

    /**
     * Descarga el artículo y extrae sus párrafos de texto significativo.
     */
    private function scrapeText(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => self::HTTP_TIMEOUT,
                'user_agent'      => self::SCRAPE_USER_AGENT,
                'follow_location' => 1,
                'header'          => "Accept: text/html,*/*\r\nAccept-Language: es-AR,es;q=0.9\r\nRange: bytes=0-" . (self::SCRAPE_MAX_BYTES - 1) . "\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            return null;
        }
        $html = '';
        while (!feof($fp) && strlen($html) < self::SCRAPE_MAX_BYTES) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) break;
            $html .= $chunk;
        }
        fclose($fp);

        if ($html === '') {
            return null;
        }

        // Detectar respuestas HTTP 4xx/5xx (evita parsear páginas de error como contenido)
        if (!empty($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m);
            if (isset($m[1]) && (int)$m[1] >= 400) {
                return null;
            }
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        foreach (self::NOISE_TAGS as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $paragraphs = [];
        foreach ($dom->getElementsByTagName('p') as $p) {
            $text = trim($p->textContent);
            if (mb_strlen($text) > self::MIN_PARA_LENGTH) {
                $paragraphs[] = $text;
            }
            if (count($paragraphs) >= self::MAX_PARAGRAPHS) {
                break;
            }
        }

        if (empty($paragraphs)) {
            return null;
        }

        // Truncar para no exceder la cuota de tokens de Gemini
        return mb_substr(implode("\n\n", $paragraphs), 0, self::MAX_TEXT_CHARS);
    }

    /**
     * Llama a Gemini para resumir el texto en español rioplatense.
     */
    private function callGemini(string $text): ?string
    {
        $apiKey = Config::get('GEMINI_API_KEY');
        if ($apiKey === null) {
            return null;
        }
        // Rate limiting: garantizar al menos GEMINI_MIN_INTERVAL_MS entre llamadas
        static $lastCallUs = 0;
        $nowUs = (int)(microtime(true) * 1_000_000);
        $minIntervalUs = self::GEMINI_MIN_INTERVAL_MS * 1_000;
        if ($lastCallUs > 0 && ($nowUs - $lastCallUs) < $minIntervalUs) {
            usleep($minIntervalUs - ($nowUs - $lastCallUs));
        }
        $lastCallUs = (int)(microtime(true) * 1_000_000);
        $prompt = "Sos un asistente de noticias en español rioplatense. "
            . "Generá un resumen claro y natural de la siguiente noticia en 3 oraciones, "
            . "sin repetir el título y sin inventar información. "
            . "Solo devolvé el texto del resumen, sin introducción ni aclaraciones.\n\n"
            . $text;

        $body = json_encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 300],
        ]);

        $endpoint = self::GEMINI_ENDPOINT . '?key=' . urlencode($apiKey);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => self::GEMINI_TIMEOUT,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($endpoint, false, $ctx);
        if ($response === false) {
            $this->log('[WARN] Gemini no respondió (timeout o red)');
            return null;
        }

        // Detectar errores HTTP de la API (429 rate-limit, 400 bad request, etc.)
        if (!empty($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m);
            $statusCode = (int)($m[1] ?? 0);
            if ($statusCode >= 400) {
                $this->log("[WARN] Gemini HTTP {$statusCode}");
                return null;
            }
        }

        $data = json_decode($response, true);
        $summary = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($summary === null) {
            $errorMsg = $data['error']['message'] ?? 'respuesta inesperada';
            $this->log('[WARN] Gemini: ' . $errorMsg);
        }

        return $summary;
    }

    /**
     * Fallback: devuelve los primeros 2 párrafos scrapeados si Gemini falla.
     */
    private function fallbackSummary(string $text): string
    {
        $paragraphs = explode("\n\n", $text);
        return implode("\n\n", array_slice($paragraphs, 0, 2));
    }

    /** Escribe una línea en el log compartido con el Aggregator. */
    private function log(string $message): void
    {
        $logFile = dirname(__DIR__) . '/data/aggregator.log';
        $line    = '[' . date('Y-m-d H:i:s') . '] [Summarizer] ' . $message . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
