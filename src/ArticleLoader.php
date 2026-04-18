<?php

declare(strict_types=1);

/**
 * Carga y prepara todos los datos de un artículo para su visualización.
 * Separa la lógica de negocio de la capa de presentación (article.php).
 */
class ArticleLoader
{
    /** Dominios con hotlink protection: se accede a sus imágenes a través del proxy. */
    private static function proxyDomains(): array
    {
        return Config::getProxyDomains();
    }

    public function __construct(
        private readonly Database $db,
        private readonly ArticleSummarizer $summarizer
    ) {}

    /**
     * Carga un artículo de la base de datos y prepara todas las variables
     * necesarias para renderizar la vista.
     *
     * @return array{title: string, source: string, pubDate: string, imageUrl: string, externalLink: string, summaryParagraphs: list<string>}|null
     *         null si el artículo no existe.
     */
    public function load(int $id): ?array
    {
        $article = $this->db->getNewsById($id);
        if (!$article) {
            return null;
        }

        $title   = htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8');
        $source  = htmlspecialchars($article['source'], ENT_QUOTES, 'UTF-8');
        $pubDate = date('d \d\e F \d\e Y, H:i', strtotime($article['pub_date']));

        $imageUrl          = self::resolveImageUrl($article);
        $externalLink      = self::resolveExternalLink($article['link']);
        $summaryParagraphs = $this->resolveSummary($article);
        $ogImageUrl        = self::resolveOgImageUrl($article);
        $pubDateIso        = date('c', strtotime($article['pub_date']));

        $rawDesc        = $article['description'] ?? '';
        $isRssSnippet   = $rawDesc !== '' && str_ends_with(rtrim($rawDesc), '...');
        $needsAiSummary = (empty($rawDesc) || $isRssSnippet)
            && !str_starts_with($article['link'], 'https://example.com');

        return compact('title', 'source', 'pubDate', 'imageUrl', 'externalLink', 'summaryParagraphs', 'ogImageUrl', 'pubDateIso', 'needsAiSummary');
    }

    /**
     * Devuelve la URL original de la imagen del artículo (sin proxy) para og:image.
     * Retorna cadena vacía si no hay imagen válida.
     */
    private static function resolveOgImageUrl(array $article): string
    {
        $rawImageUrl = trim((string)($article['image_url'] ?? ''));

        if ($rawImageUrl === '' || preg_match('~(?:picsum\.photos|images\.unsplash\.com)~i', $rawImageUrl) === 1) {
            return '';
        }

        return htmlspecialchars($rawImageUrl, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Resuelve la URL final de la imagen: vacía si es placeholder de stock,
     * proxiada si el dominio tiene hotlink protection, o directa en caso contrario.
     */
    private static function resolveImageUrl(array $article): string
    {
        $rawImageUrl = trim((string)($article['image_url'] ?? ''));

        // Descartar imágenes de stock (placeholder)
        if ($rawImageUrl !== '' && preg_match('~(?:picsum\.photos|images\.unsplash\.com)~i', $rawImageUrl) === 1) {
            $rawImageUrl = '';
        }

        if ($rawImageUrl === '') {
            return '';
        }

        $imageHost  = strtolower(parse_url($rawImageUrl, PHP_URL_HOST) ?? '');
        $needsProxy = array_reduce(
            self::proxyDomains(),
            fn ($carry, $d) => $carry || $imageHost === $d || str_ends_with($imageHost, '.' . $d),
            false
        );

        return $needsProxy
            ? 'api/img.php?url=' . urlencode($rawImageUrl)
            : htmlspecialchars($rawImageUrl, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Valida que el enlace externo use un esquema seguro (http/https)
     * para prevenir inyección de URLs tipo javascript: o data:.
     */
    private static function resolveExternalLink(string $rawLink): string
    {
        $scheme = strtolower(parse_url($rawLink, PHP_URL_SCHEME) ?? '');

        return in_array($scheme, ['http', 'https'], true)
            ? htmlspecialchars($rawLink, ENT_QUOTES, 'UTF-8')
            : htmlspecialchars('index.php', ENT_QUOTES, 'UTF-8');
    }

    /**
     * Devuelve el resumen del artículo dividido en párrafos.
     * Si no existe o es un snippet de RSS, genera uno nuevo con Gemini.
     *
     * @return list<string>
     */
    private function resolveSummary(array $article): array
    {
        $rawSummary = $article['description'] ?? '';

        // La generación por Gemini se realiza de forma asíncrona via api/summary.php
        // para no bloquear el renderizado de la página (evita TTFB alto).
        if (empty($rawSummary) || str_ends_with(rtrim($rawSummary), '...')) {
            return [];
        }

        $paragraphs = [];
        foreach (explode("\n\n", $rawSummary) as $para) {
            $para = trim($para);
            if ($para !== '') {
                $paragraphs[] = htmlspecialchars($para, ENT_QUOTES, 'UTF-8');
            }
        }

        return $paragraphs;
    }
}
