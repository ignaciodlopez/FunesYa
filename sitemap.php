<?php
declare(strict_types=1);

/**
 * Sitemap XML dinámico — FunesYa
 * Incluye la portada + todas las páginas de artículos.
 * Referenciado en robots.txt y en el <head> de cada página.
 * Máximo 50 000 URLs por archivo (límite de Google Sitemaps).
 */

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Config.php';

Config::bootstrap();

const SITEMAP_SITE_URL = 'https://www.funesya.com.ar';

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');   // El sitemap en sí no debe indexarse

$db       = new Database();
$articles = $db->getAllForSitemap();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">

  <!-- Portada -->
  <url>
    <loc><?= SITEMAP_SITE_URL ?>/</loc>
    <changefreq>always</changefreq>
    <priority>1.0</priority>
  </url>

<?php foreach ($articles as $article):
    $loc     = SITEMAP_SITE_URL . '/article.php?id=' . (int)$article['id'];
    $lastmod = date('Y-m-d', strtotime($article['pub_date']));
    // Artículos de las últimas 48 h se incluyen como Google News items
    $isRecent = (time() - strtotime($article['pub_date'])) < 172800;
?>
  <url>
    <loc><?= htmlspecialchars($loc, ENT_XML1, 'UTF-8') ?></loc>
    <lastmod><?= $lastmod ?></lastmod>
    <changefreq>never</changefreq>
    <priority>0.7</priority>
  </url>
<?php endforeach; ?>

</urlset>
