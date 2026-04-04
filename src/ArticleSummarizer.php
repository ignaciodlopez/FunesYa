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
    private const GEMINI_ENDPOINT =
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

    private const NOISE_TAGS = [
        'script', 'style', 'nav', 'footer',
        'aside', 'form', 'header', 'figure', 'figcaption',
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
                'user_agent'      => 'FunesNewsAgent/1.0',
                'follow_location' => 1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false) {
            return null;
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

        return empty($paragraphs) ? null : implode("\n\n", $paragraphs);
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
            return null;
        }

        $data = json_decode($response, true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /**
     * Fallback: devuelve los primeros 2 párrafos scrapeados si Gemini falla.
     */
    private function fallbackSummary(string $text): string
    {
        $paragraphs = explode("\n\n", $text);
        return implode("\n\n", array_slice($paragraphs, 0, 2));
    }
}
