<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = getDB();
$ns = "UPPER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nom_mpio,'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),'ñ','n')))";
foreach (['CALI','CARTAGENA','MEDELLIN','BOGOTA','BARRANQUILLA','BUCARAMANGA','VILLAVICENCIO','IBAGUE','PEREIRA','MONTERIA','CUCUTA'] as $search) {
    $r = $pdo->query("SELECT id, nom_dpto, nom_mpio, abr_mpio FROM dim_geo WHERE nivel=4 AND ($ns = '$search' OR abr_mpio='$search') LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    echo "$search:\n";
    if ($r) foreach ($r as $row) echo "  id={$row['id']} nom_mpio='{$row['nom_mpio']}' dpto='{$row['nom_dpto']}'\n";
    else echo "  (no match)\n";
}
