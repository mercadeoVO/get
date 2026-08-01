<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = getDB();
$out = [];

$tables = ['dim_barrio','dim_comuna','dim_municipio','dim_zona','dim_subregion','dim_departamento'];

foreach ($tables as $t) {
    try {
        $out["desc_$t"]   = $pdo->query("DESCRIBE c98_geo.$t")->fetchAll(PDO::FETCH_ASSOC);
        $out["sample_$t"] = $pdo->query("SELECT * FROM c98_geo.$t LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        $out["desc_$t"] = 'ERROR: '.$e->getMessage();
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
