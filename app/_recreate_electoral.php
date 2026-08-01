<?php
require_once __DIR__.'/config.php';
header('Content-Type: application/json');
$db = getDB();
$out = [];

// 1. DROP tabla actual (los datos de prueba no importan)
try {
    $db->exec("DROP TABLE IF EXISTS import_electoral");
    $out['drop'] = 'OK';
} catch(\Throwable $e){ $out['drop_err'] = $e->getMessage(); }

// 2. CREATE nueva tabla normalizada
try {
    $db->exec("CREATE TABLE import_electoral (
        id                INT(11) AUTO_INCREMENT PRIMARY KEY,
        import_id         INT(11) NOT NULL,
        id_participacion  INT(11) NULL,
        electoral_objetivo VARCHAR(60) NOT NULL,
        pregunta          VARCHAR(500) NOT NULL,
        id_candidato      INT(11) NULL,
        total_respuestas  INT(11) NOT NULL DEFAULT 0,
        creado_en         DATETIME DEFAULT NOW(),
        CONSTRAINT fk_elec_import
            FOREIGN KEY (import_id) REFERENCES import_log(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_elec_participacion
            FOREIGN KEY (id_participacion) REFERENCES dim_participacion(id_participacion)
            ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_elec_candidato
            FOREIGN KEY (id_candidato) REFERENCES dim_candidato(id_candidato)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out['create'] = 'OK';
} catch(\Throwable $e){ $out['create_err'] = $e->getMessage(); }

// 3. Verificar
$out['describe'] = $db->query("DESCRIBE import_electoral")->fetchAll(PDO::FETCH_ASSOC);
$out['fks'] = $db->query(
    "SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, rc.DELETE_RULE
     FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
     JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
       ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
      AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
     WHERE kcu.TABLE_SCHEMA=DATABASE() AND kcu.TABLE_NAME='import_electoral'
       AND kcu.REFERENCED_TABLE_NAME IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
