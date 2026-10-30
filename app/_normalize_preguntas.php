<?php
/**
 * GET · Normalizar import_log_leads_preguntas
 *
 *  PASO 0 (SQL)  · _ → espacio, trim
 *  PASO 1 (PHP)  · strip prefijo numérico "N. " / "N. N. " al inicio
 *                · fusiona duplicados que queden con el mismo texto
 *  PASO 2        · verifica unicidad y residuos
 *
 *  Idempotente. Ejecutar: http://localhost/get/_normalize_preguntas.php
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$db  = getDB();
$log = [];
$t0  = microtime(true);

function normPreg(string $s): string {
    $s = trim($s);
    $s = str_replace('_', ' ', $s);
    $s = preg_replace('/\s{2,}/u', ' ', $s);
    $s = preg_replace('/^(\d+\.\s+)+/u', '', $s);
    return trim($s);
}

// ── PASO 0: SQL rápido — _ → espacio + trim ──────────────────
$log[] = "<strong>── PASO 0: _ → espacio + trim (SQL) ──</strong>";
$aff0 = $db->exec("
    UPDATE import_log_leads_preguntas
    SET pregunta = TRIM(REPLACE(pregunta, '_', ' '))
    WHERE pregunta IS NOT NULL
");
$log[] = "  ✅ <b>$aff0</b> filas actualizadas";

// ── PASO 1: strip prefijo numérico + deduplicación (PHP) ─────
$log[] = "<strong>── PASO 1: Strip prefijo + deduplicación (PHP) ──</strong>";

$rows = $db->query("SELECT id, pregunta FROM import_log_leads_preguntas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por texto normalizado → ids[]
$groups   = [];
$origText = [];
foreach ($rows as $r) {
    $origText[(int)$r['id']] = $r['pregunta'];
    $groups[normPreg($r['pregunta'])][] = (int)$r['id'];
}

$db->exec("SET FOREIGN_KEY_CHECKS = 0");
$db->exec("SET SESSION group_concat_max_len = 10000000");
$stmtUpd = $db->prepare("UPDATE import_log_leads_preguntas SET pregunta = ? WHERE id = ?");

$updated = 0;
$merged  = 0;
$ejs     = [];

foreach ($groups as $normText => $ids) {
    sort($ids);
    $keepId = $ids[0];
    $dupIds = array_slice($ids, 1);

    // A. Fusionar duplicados ANTES de actualizar texto (evita violación UK)
    if (!empty($dupIds)) {
        $dupList = implode(',', $dupIds);
        $db->exec("UPDATE import_log_leads_respuestas SET pregunta_id = $keepId WHERE pregunta_id IN ($dupList)");
        // Reconstruir leads_ids desde respuestas
        $newIds = $db->query(
            "SELECT CONCAT('[', GROUP_CONCAT(DISTINCT lead_id ORDER BY lead_id SEPARATOR ','), ']')
             FROM import_log_leads_respuestas WHERE pregunta_id = $keepId"
        )->fetchColumn();
        if ($newIds) $db->prepare("UPDATE import_log_leads_preguntas SET leads_ids = ? WHERE id = $keepId")->execute([$newIds]);
        $db->exec("DELETE FROM import_log_leads_preguntas WHERE id IN ($dupList)");
        $merged += count($dupIds);
        if (count($ejs) < 4) $ejs[] = "  🔀 [{$keepId}] fusionó ids [$dupList] → «" . mb_substr($normText,0,60) . "»";
    }

    // B. Actualizar texto si cambió
    if (($origText[$keepId] ?? '') !== $normText) {
        $stmtUpd->execute([$normText, $keepId]);
        $updated++;
        if (count($ejs) < 8) $ejs[] = "  ✏️  [{$keepId}] <span style='color:#f87171'>" . mb_substr($origText[$keepId]??'',0,60)
            . "</span> → <span style='color:#4ade80'>" . mb_substr($normText,0,60) . "</span>";
    }
}

$db->exec("SET FOREIGN_KEY_CHECKS = 1");

$log[] = "  ✅ <b>$updated</b> textos normalizados  |  <b>$merged</b> duplicados fusionados";
foreach ($ejs as $e) $log[] = $e;

// ── PASO 2: verificación ─────────────────────────────────────
$log[] = "<strong>── PASO 2: Verificación ──</strong>";

$dups = $db->query("
    SELECT COUNT(*) FROM (
        SELECT pregunta FROM import_log_leads_preguntas GROUP BY pregunta HAVING COUNT(*) > 1
    ) t
")->fetchColumn();
$log[] = $dups == 0 ? "  ✅ Sin duplicados" : "  ⚠️  $dups grupos duplicados — vuelve a ejecutar";

$residual = 0;
foreach ($db->query("SELECT pregunta FROM import_log_leads_preguntas")->fetchAll(PDO::FETCH_COLUMN) as $p) {
    if (normPreg($p) !== $p) $residual++;
}
$log[] = $residual == 0 ? "  ✅ Sin prefijos numéricos residuales" : "  ⚠️  $residual preguntas con prefijo residual — vuelve a ejecutar";

// Resumen final
$stats = $db->query("
    SELECT COUNT(*) AS total,
           SUM(es_electoral=1) AS elec,
           SUM(es_electoral=0) AS no_elec,
           SUM(leads_ids IS NOT NULL) AS con_ids
    FROM import_log_leads_preguntas
")->fetch(PDO::FETCH_ASSOC);
$log[] = "<strong>── Catálogo final ──</strong>";
$log[] = "  Total: <b>{$stats['total']}</b>  ·  Electorales: <b>{$stats['elec']}</b>  ·  No electorales: <b>{$stats['no_elec']}</b>  ·  Con leads_ids: <b>{$stats['con_ids']}</b>";

// Muestra primeras 15
$log[] = "<strong>── Primeras 15 preguntas ──</strong>";
foreach ($db->query("SELECT id, pregunta, es_electoral, JSON_LENGTH(leads_ids) AS n FROM import_log_leads_preguntas ORDER BY id LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $e = $r['es_electoral'] ? " [electoral]" : "";
    $log[] = "  [{$r['id']}]{$e} {$r['pregunta']} <span style='color:#6366f1'>({$r['n']} leads)</span>";
}

$log[] = "<strong>⏱️  " . round(microtime(true)-$t0, 2) . "s</strong>";
?><!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>normalize preguntas</title>
<style>
body{font-family:'Segoe UI',monospace;background:#0f1117;color:#e8eaf0;padding:30px;line-height:2}
h2{color:#6366f1;margin-bottom:4px}.sub{color:#7b84a3;font-size:.82rem;margin-bottom:20px}
.log{background:#1e2132;border:1px solid #2d3148;border-radius:8px;padding:20px;max-width:1100px;font-size:.83rem}
.log p{margin:1px 0;padding:2px 0;border-bottom:1px solid rgba(255,255,255,.04)}
code{background:#0f1117;padding:1px 5px;border-radius:3px;color:#a5b4fc}
</style></head>
<body>
<h2>📝 GET · Normalizar Preguntas</h2>
<p class="sub">_ → espacio · trim · strip "N. " · deduplicar</p>
<div class="log">
<?php foreach ($log as $line): ?><p><?= $line ?></p><?php endforeach; ?>
</div>
<p style="margin-top:12px;color:#7b84a3;font-size:.78rem">
  <a href="/get/leads_analytics.html" style="color:#6366f1">← Dashboard</a>
</p>
</body></html>
