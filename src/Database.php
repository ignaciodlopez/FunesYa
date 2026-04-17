<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Capa de acceso a datos SQLite.
 * Gestiona la tabla de noticias y la configuración clave-valor de la aplicación.
 */
class Database
{
    private PDO $pdo;

    /** Abre (o crea) la base de datos SQLite e inicializa las tablas necesarias. */
    public function __construct() {
        $dbPath = __DIR__ . '/../data/news.sqlite';
        $this->pdo = new PDO("sqlite:" . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }

    /** Crea las tablas de la base de datos si todavía no existen. */
    private function init(): void {
        // Tabla principal de noticias (link único para evitar duplicados)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS news (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                link TEXT NOT NULL UNIQUE,
                image_url TEXT,
                source TEXT NOT NULL,
                pub_date DATETIME NOT NULL,
                description TEXT
            )
        ");

        // Migración: agregar columna description si no existe (bases de datos previas)
        try {
            $this->pdo->exec("ALTER TABLE news ADD COLUMN description TEXT");
        } catch (\Exception $e) {
            // La columna ya existe, no hay nada que hacer
        }

        // Migración: clave canónica para deduplicación robusta (ej. InfoFunes cambia slugs)
        // El índice es parcial (WHERE canonical_key IS NOT NULL) para no afectar fuentes
        // sin clave canónica propia — SQLite trata los NULL como valores distintos.
        try {
            $this->pdo->exec("ALTER TABLE news ADD COLUMN canonical_key TEXT");
        } catch (\Exception $e) {
            // La columna ya existe
        }
        $this->pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_canonical_key
            ON news (canonical_key)
            WHERE canonical_key IS NOT NULL
        ");

        // Índices de rendimiento para las queries más frecuentes.
        // idx_pub_date: acelera ORDER BY pub_date DESC (lista principal).
        // idx_source_date: acelera WHERE source = ? ORDER BY pub_date DESC (filtros por fuente).
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_pub_date    ON news (pub_date DESC)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_source_date ON news (source, pub_date DESC)");

        // Tabla de configuración clave-valor (ej: timestamp de última actualización)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS config (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ");

        $this->normalizeLegacyUtcDates();
    }

    /**
     * Ajusta una sola vez las fechas históricas que quedaron almacenadas en UTC
     * antes de fijar la zona horaria local del proyecto.
     */
    private function normalizeLegacyUtcDates(): void {
        $stmt = $this->pdo->query("SELECT value FROM config WHERE key = 'pub_date_timezone_fix_v1'");
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("
                UPDATE news
                SET pub_date = datetime(pub_date, '-3 hours')
                WHERE pub_date IS NOT NULL
                  AND LENGTH(pub_date) = 19
            ");

            $stmt = $this->pdo->prepare("
                INSERT OR REPLACE INTO config (key, value)
                VALUES ('pub_date_timezone_fix_v1', :val)
            ");
            $stmt->execute([':val' => (string)time()]);

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Inserta un array de noticias en la base de datos.
     * Ignora duplicados gracias a la restricción UNIQUE en el campo link.
     *
     * @param array $newsItems Lista de noticias a guardar
     */
    public function saveNews(array $newsItems): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO news (title, link, image_url, source, pub_date, description, canonical_key)
            VALUES (:title, :link, :image_url, :source, :pub_date, :description, :canonical_key)
            ON CONFLICT(link) DO UPDATE SET
                image_url = CASE
                    WHEN (news.image_url IS NULL OR TRIM(news.image_url) = '' OR news.image_url LIKE 'https://picsum.photos/%' OR news.image_url LIKE 'https://images.unsplash.com/%')
                         AND excluded.image_url IS NOT NULL AND TRIM(excluded.image_url) <> ''
                    THEN excluded.image_url
                    ELSE news.image_url
                END,
                description = CASE
                    WHEN (news.description IS NULL OR TRIM(news.description) = '' OR (news.description LIKE '%...' AND LENGTH(news.description) < 500))
                         AND excluded.description IS NOT NULL AND TRIM(excluded.description) <> ''
                    THEN excluded.description
                    ELSE news.description
                END,
                pub_date = CASE
                    WHEN excluded.pub_date > news.pub_date THEN excluded.pub_date
                    ELSE news.pub_date
                END
        ");

        $inserted = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($newsItems as $item) {
                $imageUrl = $this->normalizeImageUrlForStorage($item['image_url'] ?? null);

                $stmt->execute([
                    ':title'         => $item['title'],
                    ':link'          => $item['link'],
                    ':image_url'     => $imageUrl,
                    ':source'        => $item['source'],
                    ':pub_date'      => $item['pub_date'],
                    ':description'   => $item['description'] ?? null,
                    ':canonical_key' => $item['canonical_key'] ?? null,
                ]);
                $inserted += $stmt->rowCount();
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $inserted;
    }

    private function normalizeImageUrlForStorage(?string $imageUrl): ?string {
        $imageUrl = trim((string)$imageUrl);
        if ($imageUrl === '' || preg_match('~(?:picsum\.photos|images\.unsplash\.com)~i', $imageUrl) === 1) {
            return null;
        }

        if (preg_match('#^https?://[^/]+/(https?://.+)$#i', $imageUrl, $m) === 1) {
            $imageUrl = $m[1];
        }

        $imageUrl = preg_replace('#^(https?://[^/]+)//(.+)$#', '$1/$2', $imageUrl) ?? $imageUrl;

        return $imageUrl !== '' ? $imageUrl : null;
    }

    /**
     * Devuelve noticias paginadas, opcionalmente filtradas por fuente.
     *
     * @param int         $limit  Máximo de resultados a devolver
     * @param string|null $source Nombre del medio o null para todas las fuentes
     * @param int         $offset Desplazamiento para la paginación
     * @return array              Lista de noticias como arrays asociativos
     */
    public function getNews(int $limit = 12, ?string $source = null, int $offset = 0): array {
        // Excluir description y canonical_key: no se necesitan en la vista de lista
        $query = "SELECT id, title, link, image_url, source, pub_date FROM news";
        $params = [];
        
        if ($source && $source !== 'Todas') {
            $query .= " WHERE source = :source";
            $params[':source'] = $source;
        }
        
        $query .= " ORDER BY pub_date DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->pdo->prepare($query);
        if ($source && $source !== 'Todas') {
            $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Devuelve el timestamp Unix de la última actualización de noticias.
     * Retorna 0 si nunca se actualizaron los datos.
     */
    public function getLastUpdate(): int {
        $stmt = $this->pdo->query("SELECT value FROM config WHERE key = 'last_update'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['value'] : 0;
    }

    /**
     * Persiste el timestamp Unix de la última actualización de noticias.
     *
     * @param int $timestamp Tiempo Unix a guardar
     */
    public function setLastUpdate(int $timestamp): void {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO config (key, value)
            VALUES ('last_update', :val)
        ");
        $stmt->execute([':val' => (string)$timestamp]);
    }

    /**
     * Devuelve una noticia por su ID.
     *
     * @param int $id ID de la noticia
     * @return array|false Noticia como array asociativo o false si no existe
     */
    public function getNewsById(int $id): array|false {
        $stmt = $this->pdo->prepare("SELECT * FROM news WHERE id = :id");
        $stmt->execute([':id' => (int)$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Devuelve id y pub_date de todos los artículos para generar el sitemap XML.
     * Limitado a 50 000 URLs (límite de Google Sitemaps).
     *
     * @return array<array{id: int, pub_date: string}>
     */
    public function getAllForSitemap(): array {
        $stmt = $this->pdo->query(
            "SELECT id, pub_date FROM news ORDER BY pub_date DESC LIMIT 50000"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Devuelve todos los links almacenados para una fuente concreta.
     * Se usa para deduplicar artículos al agregar, evitando re-insertar
     * artículos que ya existen con distinto slug (ej. InfoFunes).
     *
     * @param string $source Nombre del medio
     * @return string[]      Array de URLs ya guardadas
     */
    public function getLinksBySource(string $source): array {
        // Limitar a los últimos 1000 registros: suficiente para deduplicar ciclos normales
        // y evita cargar en memoria tablas muy grandes.
        $stmt = $this->pdo->prepare(
            "SELECT link FROM news WHERE source = :source ORDER BY id DESC LIMIT 1000"
        );
        $stmt->execute([':source' => $source]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Guarda el resumen scrapeado en la columna description de una noticia.
     *
     * @param int    $id      ID de la noticia
     * @param string $summary Texto del resumen
     */
    public function saveSummary(int $id, string $summary): void {
        $stmt = $this->pdo->prepare("UPDATE news SET description = :desc WHERE id = :id");
        $stmt->execute([':desc' => $summary, ':id' => $id]);
    }

    /**
     * Devuelve artículos sin descripción o con snippet RSS que necesitan resumen IA.
     * Un snippet se detecta como descripción que termina en '...' y tiene menos de 500 chars.
     *
     * @return array Lista de artículos con id, title, link, description
     */
    public function getArticlesNeedingSummary(int $limit = 50): array {
        $stmt = $this->pdo->prepare("
            SELECT id, title, link, description
            FROM news
            WHERE link NOT LIKE 'https://example.com%'
              AND (
                  description IS NULL
                  OR (description LIKE '%...' AND LENGTH(description) < 500)
              )
            ORDER BY id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Devuelve la lista de fuentes presentes en la base de datos, sin duplicados y ordenadas.
     *
     * @return array Lista de nombres de fuentes (strings)
     */
    public function getSources(): array {
        $stmt = $this->pdo->query("SELECT DISTINCT source FROM news ORDER BY source ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Devuelve métricas agregadas por fuente para monitoreo y diagnóstico.
     * Incluye volumen total, actividad reciente y cobertura básica de imágenes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSourceStats(): array {
        $stmt = $this->pdo->query("
            SELECT
                n.source,
                COUNT(*) AS total_articles,
                SUM(CASE WHEN n.pub_date >= datetime('now', '-24 hours') THEN 1 ELSE 0 END) AS recent_articles,
                MAX(n.pub_date) AS latest_pub_date,
                SUM(CASE WHEN COALESCE(n.image_url, '') <> '' THEN 1 ELSE 0 END) AS articles_with_image,
                SUM(CASE WHEN n.image_url LIKE 'https://picsum.photos/%' THEN 1 ELSE 0 END) AS placeholder_images,
                (
                    SELECT n2.title
                    FROM news n2
                    WHERE n2.source = n.source
                    ORDER BY n2.pub_date DESC, n2.id DESC
                    LIMIT 1
                ) AS latest_title
            FROM news n
            GROUP BY n.source
            ORDER BY n.source ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
