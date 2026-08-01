<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = getDB();

// Verificar que dim_geo existe
$exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='medfut' AND TABLE_NAME='dim_geo'")->fetchColumn();
if (!$exists) { echo "dim_geo ya no existe.\n"; exit; }

// Verificar que id_geo en import_log_leads ya NO es INT (ya fue migrado)
$tipo = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='medfut' AND TABLE_NAME='import_log_leads' AND COLUMN_NAME='id_geo'")->fetchColumn();
if ($tipo === 'int') { echo "ERROR: id_geo sigue siendo INT — migración no completada.\n"; exit; }

// Buscar FK constraints que referencien dim_geo
$fks = $pdo->query("
    SELECT TABLE_NAME, CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE REFERENCED_TABLE_SCHEMA='medfut'
      AND REFERENCED_TABLE_NAME='dim_geo'
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($fks as $fk) {
    echo "Dropping FK {$fk['CONSTRAINT_NAME']} on {$fk['TABLE_NAME']}...\n";
    $pdo->exec("ALTER TABLE medfut.`{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
    echo "  OK\n";
}

// DROP
$pdo->exec("DROP TABLE medfut.dim_geo");
echo "OK: DROP TABLE medfut.dim_geo completado. tipo id_geo=$tipo\n";
