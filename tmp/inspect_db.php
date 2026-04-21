<?php
$dbPdo = new PDO('sqlite:data/news.sqlite');
$dbPdo->exec("UPDATE news SET image_url = NULL WHERE title LIKE '%Consiglio%'");
echo "Consiglio reset done.\n";
