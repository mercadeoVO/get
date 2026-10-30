<?php
/**
 * GET · Normalizar import_log_leads_respuestas
 *
 *  Aplica sobre la columna `respuesta`:
 *    · Reemplaza _ por espacio
 *    · Elimina espacios al inicio y al final (TRIM)
 *    · Colapsa espacios múltiples
 *
 *  Idempotente. Ejecutar: http://localhost/get/_normalize_respuestas.php
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$db  = getDB();
$log = [];
$t0  = microtime(true);

$log[] = "<strong>── Normalizando import_log_leads_respuestas ──</strong>";

$total = (int)$db->query("SELECT COUNT(*) FROM import_log_leads_respuestas")->fetchColumn();
$log[] = "  Total respuestas: <b>" . number_format($total) . "</b>";

$aff = $db->exec("
    UPDATE import_log_leads_respuestas
    SET respuesta = TRIM(REGEXP_REPLACE(REPLACE(respuesta, '_', ' '), '  +', ' '))
    WHERE respuesta IS NOT NULL
");
$log[] = "  ✅ <b>$aff</b> filas actualizadas";

// Distribución de respuestas únicas post-normalización
$log[] = "<strong>── Top 20 respuestas más frecuentes (post-normalización) ──</strong>";
$top = $db->query("
    SELECT respuesta, COUNT(*) AS n
    FROM import_log_leads_respuestas
    WHERE respuesta IS NOT NULL AND respuesta != ''
    GROUP BY respuesta
    ORDER BY n DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($top as $r) {
    $log[] = "  <b>" . number_format($r['n']) . "x</b>  {$r['respuesta']}";
}

// Verificar si quedan respuestas con _
$conGuion = (int)$db->query("
    SELECT COUNT(*) FROM import_log_leads_respuestas WHERE respuesta LIKE '%\_%'
")->fetchColumn();
$log[] = $conGuion === 0
    ? "  ✅ Sin respuestas con guion bajo residual"
    : "  ⚠️  $conGuion respuestas aún contienen _ — vuelve a ejecutar";

$log[] = "<strong>⏱️  " . round(microtime(true)-$t0, 2) . "s</strong>";
?><!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>normalize respuestas</title>
<style>
body{font-family:'Segoe UI',monospace;background:#0f1117;color:#e8eaf0;padding:30px;line-height:2}
h2{color:#6366f1;margin-bottom:4px}.sub{color:#7b84a3;font-size:.82rem;margin-bottom:20px}
.log{background:#1e2132;border:1px solid #2d3148;border-radius:8px;padding:20px;max-width:1100px;font-size:.83rem}
.log p{margin:1px 0;padding:2px 0;border-bottom:1px solid rgba(255,255,255,.04)}
</style></head>
<body>
<h2>💬 GET · Normalizar Respuestas</h2>
<p class="sub">_ → espacio · trim · colapsar espacios</p>
<div class="log">
<?php foreach ($log as $line): ?><p><?= $line ?></p><?php endforeach; ?>
</div>
<p style="margin-top:12px;color:#7b84a3;font-size:.78rem">
  <a href="/get/leads_analytics.html" style="color:#6366f1">← Dashboard</a>
</p>
</body></html>
