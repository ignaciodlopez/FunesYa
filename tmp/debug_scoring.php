<?php
require_once 'src/Aggregator.php';
require_once 'src/Database.php';

$agg = new Aggregator(new Database('data/news.sqlite'));
$url = 'https://estacionline.com/energia-funes-consiglio-epe-generacion-local/';

// Refleccion para acceder a metodos privados para debug
$reflector = new ReflectionClass($agg);
$method = $reflector->getMethod('fetchOgImage');
$method->setAccessible(true);

echo "Buscando imagen para: $url\n";
// Capturamos el HTML que el Aggregator ve
$reflectorHtml = $reflector->getProperty('logFile'); // Solo por si acaso
$agg = new Aggregator(new Database('data/news.sqlite'));

$result = $method->invoke($agg, $url);
file_put_contents('tmp/debug_html.html', "HTML capture not possible directly from here, but I will simulate the request.");
echo "RESULTADO FINAL: $result\n";
