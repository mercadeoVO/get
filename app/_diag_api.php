<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$db = getDB();
$out = [];

$tables = ['dim_participacion','dim_geografia','dim_geo','fact_votos','fact_encuesta_agg'];
foreach ($tables as $t) {
    $exists = (bool)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
    if ($exists) {
        $cols = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'
            ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $rows = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $out[$t] = ['existe'=>true,'columnas'=>$cols,'filas'=>$rows];
    } else {
        $out[$t] = ['existe'=>false];
    }
}
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
