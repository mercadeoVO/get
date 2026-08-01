<?php
// MEDFUT - Script de migración de vista v_import_resumen
// Visitar UNA VEZ desde: http://localhost/medfut/run_migration.php
// Luego eliminar este archivo.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();

    // Columnas en import_log (idempotente — ignora si ya existen)
    try { $db->exec("ALTER TABLE import_log ADD COLUMN etapa VARCHAR(50) DEFAULT 'importado'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE import_log ADD COLUMN url_carpeta VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE import_log ADD COLUMN url_archivo VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}

    // Columnas en leads (idempotente)
    try { $db->exec("ALTER TABLE import_log_leads ADD COLUMN nivel_educativo VARCHAR(100) DEFAULT NULL"); } catch(Exception $e) {}

    // Vista actualizada
    $db->exec("CREATE OR REPLACE VIEW v_import_resumen AS
        SELECT
            il.id, il.archivo, il.hoja, il.importado_en,
            il.filas_proc, il.filas_ins, il.filas_dup, il.filas_err,
            il.notas, il.etapa, il.url_carpeta, il.url_archivo,
            COUNT(DISTINCT l.id) AS leads_en_bd
        FROM import_log il
        LEFT JOIN import_log_leads l ON l.import_id = il.id
        GROUP BY il.id
        ORDER BY il.importado_en DESC");

    echo "✅ Migración completada:\n";
    echo "   - import_log: etapa, url_carpeta, url_archivo\n";
    echo "   - import_log_leads: nivel_educativo\n";
    echo "   - Vista v_import_resumen actualizada\n\n";
    echo "Puedes eliminar este archivo: run_migration.php\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
