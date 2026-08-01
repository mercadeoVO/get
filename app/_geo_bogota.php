<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = getDB();
echo "=== Bogota en dim_geo (nivel=4) ===\n";
$r = $pdo->query("SELECT id, nom_dpto, nom_mpio, abr_mpio FROM dim_geo WHERE nivel=4 AND (nom_mpio LIKE '%ogot%' OR nom_dpto LIKE '%ogot%')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r as $row) print_r($row);
echo "\n=== Rionegro ===\n";
$r2 = $pdo->query("SELECT id, nom_dpto, nom_mpio FROM dim_geo WHERE nivel=4 AND UPPER(nom_mpio) LIKE '%RIONEG%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r2 as $row) print_r($row);
echo "\n=== San Andres ===\n";
$r3 = $pdo->query("SELECT id, nom_dpto, nom_mpio FROM dim_geo WHERE nivel=4 AND UPPER(nom_mpio) LIKE '%ANDR%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($r3 as $row) print_r($row);
