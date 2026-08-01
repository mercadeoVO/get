<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = getDB();

echo "=== import_log_leads ===\n";
foreach ($pdo->query("DESCRIBE import_log_leads")->fetchAll(PDO::FETCH_ASSOC) as $c)
    echo $c['Field'].' | '.$c['Type'].' | Null:'.$c['Null'].' | Key:'.$c['Key'].' | Default:'.$c['Default']."\n";

echo "\n=== Sample geo data (5 rows) ===\n";
$rows = $pdo->query("
    SELECT id_lead, geo_departamento, municipio, subregion, zona, comuna_localidad, barrio, id_geo
    FROM import_log_leads LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r)
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== Geo field fill rates ===\n";
$stats = $pdo->query("
    SELECT
      COUNT(*) total,
      SUM(geo_departamento IS NOT NULL AND geo_departamento != '') n_dpto,
      SUM(municipio IS NOT NULL AND municipio != '') n_mpio,
      SUM(subregion IS NOT NULL AND subregion != '') n_subr,
      SUM(zona IS NOT NULL AND zona != '') n_zona,
      SUM(comuna_localidad IS NOT NULL AND comuna_localidad != '') n_com,
      SUM(barrio IS NOT NULL AND barrio != '') n_bar,
      SUM(id_geo IS NOT NULL) n_id_geo
    FROM import_log_leads
")->fetch(PDO::FETCH_ASSOC);
foreach ($stats as $k=>$v) echo "$k: $v\n";

echo "\n=== Municipios distintos en leads ===\n";
$mpios = $pdo->query("
    SELECT municipio, COUNT(*) n FROM import_log_leads
    WHERE municipio IS NOT NULL AND municipio != ''
    GROUP BY municipio ORDER BY n DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($mpios as $r) echo $r['municipio'].' => '.$r['n']."\n";
