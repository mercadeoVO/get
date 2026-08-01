<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$db = getDB();

$tables = ['import_log','import_log_leads','import_log_leads_respuestas',
           'import_log_leads_preguntas','import_electoral'];
$out = [];

foreach ($tables as $t) {
    // Columnas
    try {
        $cols = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
                            FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'
                            ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $out[$t]['columnas'] = $cols;
    } catch(\Exception $e) { $out[$t]['columnas'] = 'ERROR: '.$e->getMessage(); }

    // Índices
    try {
        $idx = $db->query("SHOW INDEX FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        $out[$t]['indices'] = array_map(fn($i)=>[
            'key'   => $i['Key_name'],
            'col'   => $i['Column_name'],
            'unique'=> $i['Non_unique']==0 ? 'SI':'NO'
        ], $idx);
    } catch(\Exception $e) { $out[$t]['indices'] = 'ERROR: '.$e->getMessage(); }

    // FKs
    try {
        $fks = $db->query("
            SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                   kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                   rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
            WHERE kcu.TABLE_SCHEMA=DATABASE() AND kcu.TABLE_NAME='$t'
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out[$t]['fks'] = $fks;
    } catch(\Exception $e) { $out[$t]['fks'] = 'ERROR: '.$e->getMessage(); }

    // Conteo
    try { $out[$t]['filas'] = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
    catch(\Exception $e) { $out[$t]['filas'] = 'ERROR'; }

    // ¿Tiene import_id?
    try {
        $out[$t]['tiene_import_id'] = (bool)$db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='import_id'"
        )->fetchColumn();
    } catch(\Exception $e) { $out[$t]['tiene_import_id'] = false; }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
