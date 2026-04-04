<?php
declare(strict_types=1);

/**
 * Capa de acceso a datos SQLite.
 * Gestiona la tabla de noticias y la configuración clave-valor de la aplicación.
 */
class Database {
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

        // Tabla de configuración clave-valor (ej: timestamp de última actualización)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS config (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ");
    }

    /**
     * Inserta un array de noticias en la base de datos.
     * Ignora duplicados gracias a la restricción UNIQUE en el campo link.
     *
     * @param array $newsItems Lista de noticias a guardar
     */
    public function saveNews(array $newsItems): void {
        $stmt = $this->pdo->prepare("
            INSERT OR IGNORE INTO news (title, link, image_url, source, pub_date, description, canonical_key)
            VALUES (:title, :link, :image_url, :source, :pub_date, :description, :canonical_key)
        ");

        // Transacción para mejorar el rendimiento en inserciones masivas
        $this->pdo->beginTransaction();
        foreach ($newsItems as $item) {
            $stmt->execute([
                ':title'         => $item['title'],
                ':link'          => $item['link'],
                ':image_url'     => $item['image_url'],
                ':source'        => $item['source'],
                ':pub_date'      => $item['pub_date'],
                ':description'   => $item['description'] ?? null,
                ':canonical_key' => $item['canonical_key'] ?? null,
            ]);
        }
        $this->pdo->commit();
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
        $query = "SELECT * FROM news";
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
     * Devuelve todos los links almacenados para una fuente concreta.
     * Se usa para deduplicar artículos al agregar, evitando re-insertar
     * artículos que ya existen con distinto slug (ej. InfoFunes).
     *
     * @param string $source Nombre del medio
     * @return string[]      Array de URLs ya guardadas
     */
    public function getLinksBySource(string $source): array {
        $stmt = $this->pdo->prepare("SELECT link FROM news WHERE source = :source");
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
     * Devuelve la lista de fuentes presentes en la base de datos, sin duplicados y ordenadas.
     *
     * @return array Lista de nombres de fuentes (strings)
     */
    public function getSources(): array {
        $stmt = $this->pdo->query("SELECT DISTINCT source FROM news ORDER BY source ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
