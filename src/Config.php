<?php

declare(strict_types=1);

/**
 * Lee variables de configuración desde el archivo .env del proyecto.
 */
class Config
{
    private static array $cache = [];
    private static bool $loaded = false;

    /**
     * Inicializa la configuración global del proyecto.
     * Actualmente fija la zona horaria local para evitar desfases de fecha.
     */
    public static function bootstrap(): void
    {
        if (!self::$loaded) {
            self::load();
        }

        $timezone = self::$cache['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? 'America/Argentina/Buenos_Aires';
        $timezone = is_string($timezone) ? trim($timezone) : '';

        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'America/Argentina/Buenos_Aires';
        }

        if (date_default_timezone_get() !== $timezone) {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * Devuelve los feeds RSS configurados, indexados por nombre del medio.
     *
     * @return array<string, string>
     */
    public static function getFeeds(): array
    {
        return [
            'InfoFunes'       => 'https://infofunes.com.ar/rss.xml',
            'La Voz de Funes' => 'https://lavozdefunes.com.ar/rss',
            'Funes Hoy'       => 'https://funeshoy.com.ar/feed/',
            'El Occidental'   => 'https://eloccidental.com.ar/feed/',
            'Estacionline'    => 'https://estacionline.com/feed/',
        ];
    }

    /**
     * Devuelve los sitios sin RSS que se obtienen por scraping, indexados por nombre.
     *
     * @return array<string, string>
     */
    public static function getScrapers(): array
    {
        return [
            'FM Diez Funes' => 'https://www.fmdiezfunes.com.ar/noticias.php',
        ];
    }

    /**
     * Dominios con hotlink protection: sus imágenes deben servirse a través del proxy.
     * Esta es la fuente única de verdad — usada por Aggregator, ArticleLoader, index.php y main.js.
     *
     * @return string[]
     */
    public static function getProxyDomains(): array
    {
        return [
            'lavozdefunes.com.ar',
            'estacionline.com',
            'flex-assets.tadevel-cdn.com',
            'fmdiezfunes.com.ar',
            'funeshoy.com.ar',
            'eloccidental.com.ar',
            'infofunes.com.ar',
            'infobae.com',
            'tn.com.ar',
            'radiofonica.com',
            'ambito.com',
            'media.ambito.com',
            'elliberador.com',
            'resizer.glanacion.com',
            'resizer.lavoz.com.ar',
            'assets.dev-filo.dift.io',
            'cloudfront.net',
        ];
    }

    /**
     * Devuelve el valor de una variable de entorno.
     * Primero busca en el .env del proyecto, luego en las variables del sistema.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$cache[$key] ?? $default;
    }

    private static function load(): void
    {
        self::$loaded = true;

        $envFile = dirname(__DIR__) . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2)) + [1 => ''];
            if ($key !== '') {
                self::$cache[$key] = $value;
            }
        }
    }
}

Config::bootstrap();
