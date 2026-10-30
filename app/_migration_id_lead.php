<?php
/**
 * GET · Migración de IDs + Normalización de Leads
 *
 *  RESULTADO FINAL:
 *    import_log_leads         PK = id_lead INT AUTO_INCREMENT  /  id VARCHAR UNIQUE
 *    import_log_leads_respuestas  lead_id INT FK → id_lead   (sin import_id ni lead_id VARCHAR)
 *    import_log_leads_preguntas   leads_ids JSON [array de id_lead]
 *    Columnas de texto de leads:  _ → espacio · trim · colapsar espacios
 *
 *  Idempotente — detecta el estado actual y continúa desde donde corresponde.
 *  Ejecutar: http://localhost/medfut/_migration_id_lead.php
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$db  = getDB();
$log = [];
$t0  = microtime(true);

// ── Helpers ─────────────────────────────────────────────────────────
function hasCol(PDO $db, string $tbl, string $col): bool {
    return (bool)$db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'"
    )->fetchColumn();
}
function colType(PDO $db, string $tbl, string $col): string|false {
    return $db->query(
        "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'"
    )->fetchColumn();
}
function fksOnCol(PDO $db, string $tbl, string $col): array {
    return $db->query(
        "SELECT kcu.CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
         JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
           ON tc.TABLE_SCHEMA=kcu.TABLE_SCHEMA AND tc.TABLE_NAME=kcu.TABLE_NAME
          AND tc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME AND tc.CONSTRAINT_TYPE='FOREIGN KEY'
         WHERE kcu.TABLE_SCHEMA=DATABASE() AND kcu.TABLE_NAME='$tbl' AND kcu.COLUMN_NAME='$col'"
    )->fetchAll(PDO::FETCH_COLUMN);
}
function dropFksOnCol(PDO $db, string $tbl, string $col, array &$log): void {
    foreach (fksOnCol($db, $tbl, $col) as $fk) {
        $db->exec("ALTER TABLE `$tbl` DROP FOREIGN KEY `$fk`");
        $log[] = "  🗑️  FK eliminada: <code>$fk</code>";
    }
}
function dropIdxOnCol(PDO $db, string $tbl, string $col, array &$log): void {
    $rows = $db->query("SHOW INDEX FROM `$tbl` WHERE Column_name='$col'")->fetchAll(PDO::FETCH_ASSOC);
    $done = [];
    foreach ($rows as $r) {
        $kn = $r['Key_name'];
        if ($kn === 'PRIMARY' || in_array($kn, $done)) continue;
        try { $db->exec("ALTER TABLE `$tbl` DROP INDEX `$kn`"); $log[] = "  🗑️  Índice eliminado: <code>$kn</code>"; } catch (\Exception $e) {}
        $done[] = $kn;
    }
}
function step(array &$log, string $msg): void { $log[] = $msg; }

// ── Diagnóstico inicial ──────────────────────────────────────────────
step($log, "<strong>── Estado actual de import_log_leads_respuestas ──</strong>");
$respCols = $db->query(
    "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='import_log_leads_respuestas'
     ORDER BY ORDINAL_POSITION"
)->fetchAll(PDO::FETCH_KEY_PAIR);

foreach ($respCols as $cn => $dt) {
    step($log, "  · <code>$cn</code> $dt");
}
step($log, "");

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// PASO 1 — Agregar id_lead a import_log_leads (si no existe)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
step($log, "<strong>── PASO 1: id_lead en import_log_leads ──</strong>");

if (hasCol($db, 'import_log_leads', 'id_lead')) {
    step($log, "⏭️  id_lead ya existe");
} else {
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Quitar cualquier FK que apunte a import_log_leads.id desde respuestas
    foreach (['lead_id', '_new_lead_id'] as $c) {
        if (isset($respCols[$c])) dropFksOnCol($db, 'import_log_leads_respuestas', $c, $log);
    }

    $db->exec("ALTER TABLE import_log_leads
               DROP PRIMARY KEY,
               ADD COLUMN id_lead INT NOT NULL AUTO_INCREMENT FIRST,
               ADD PRIMARY KEY (id_lead),
               ADD UNIQUE KEY uk_meta_lead_id (id)");
    step($log, "✅ id_lead INT AUTO_INCREMENT → PK  /  id → UNIQUE KEY");

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
}

$nLeads = (int)$db->query("SELECT COUNT(*) FROM import_log_leads")->fetchColumn();
step($log, "  📊 Leads: <b>" . number_format($nLeads) . "</b>");

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// PASO 2 — Reestructurar import_log_leads_respuestas
//
//  Detecta el estado y avanza desde ahí:
//   A) lead_id VARCHAR → migración completa
//   B) _new_lead_id INT (migración a medias) → continuar desde renombrado
//   C) lead_id INT    → ya migrado
//   D) sin lead_id    → add lead_id INT via import_id (fallback)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
step($log, "<strong>── PASO 2: reestructurar import_log_leads_respuestas ──</strong>");

// Recargar columnas actuales (pueden haber cambiado en PASO 1)
$respCols = $db->query(
    "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='import_log_leads_respuestas'
     ORDER BY ORDINAL_POSITION"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$hasLeadIdVarchar  = isset($respCols['lead_id'])     && $respCols['lead_id']     !== 'int';
$hasLeadIdInt      = isset($respCols['lead_id'])     && $respCols['lead_id']     === 'int';
$hasNewLeadId      = isset($respCols['_new_lead_id']);
$hasImportId       = isset($respCols['import_id']);

if ($hasLeadIdInt && !$hasImportId) {
    step($log, "⏭️  lead_id ya es INT y sin import_id — PASO 2 completado");

} else {
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    // ── 2A: lead_id VARCHAR existe → migración estándar ──────────
    if ($hasLeadIdVarchar) {
        step($log, "  Estado A: lead_id VARCHAR — migrando a INT via id_lead");

        dropFksOnCol($db, 'import_log_leads_respuestas', 'lead_id', $log);
        dropIdxOnCol($db, 'import_log_leads_respuestas', 'lead_id', $log);

        if (!$hasNewLeadId) {
            $db->exec("ALTER TABLE import_log_leads_respuestas
                       ADD COLUMN _new_lead_id INT NULL AFTER lead_id");
            step($log, "  ✅ _new_lead_id añadida");
        }

        $aff = $db->exec("
            UPDATE import_log_leads_respuestas r
            JOIN import_log_leads l ON l.id = r.lead_id
            SET r._new_lead_id = l.id_lead
            WHERE r._new_lead_id IS NULL
        ");
        step($log, "  ✅ _new_lead_id poblado: <b>" . number_format($aff) . " filas</b>");

        $orph = (int)$db->query(
            "SELECT COUNT(*) FROM import_log_leads_respuestas WHERE _new_lead_id IS NULL"
        )->fetchColumn();
        if ($orph) {
            $db->exec("DELETE FROM import_log_leads_respuestas WHERE _new_lead_id IS NULL");
            step($log, "  ⚠️  $orph huérfanas eliminadas");
        } else {
            step($log, "  ✅ 0 huérfanas");
        }

        $db->exec("ALTER TABLE import_log_leads_respuestas DROP COLUMN lead_id");
        step($log, "  🗑️  lead_id VARCHAR eliminado");
        $hasNewLeadId = true; // ahora necesitamos el rename
    }

    // ── 2B: _new_lead_id existe (medio camino o recién creado) ───
    if ($hasNewLeadId || (isset($respCols['_new_lead_id']))) {
        $db->exec("ALTER TABLE import_log_leads_respuestas
                   CHANGE COLUMN _new_lead_id lead_id INT NOT NULL");
        step($log, "  ✅ _new_lead_id → lead_id INT NOT NULL");
    }

    // ── 2D: sin lead_id de ningún tipo → usar import_id ─────────
    //    Solo posible si lead_id fue dropeado previamente
    if (!$hasLeadIdVarchar && !$hasNewLeadId && !$hasLeadIdInt) {
        step($log, "  Estado D: lead_id inexistente — reconstruyendo desde import_id");

        if (!$hasImportId) {
            step($log, "  ❌ CRÍTICO: import_id tampoco existe — no hay base para reconstruir");
        } else {
            // Añadir lead_id INT y poblar usando import_id + import_log_leads
            // NOTA: si hay múltiples leads por import y por pregunta, tomamos el primero por import_id
            // (en ese escenario los datos de atribución individual ya se perdieron)
            $db->exec("ALTER TABLE import_log_leads_respuestas
                       ADD COLUMN lead_id INT NULL AFTER pregunta_id");
            step($log, "  ✅ lead_id INT añadido");

            // Asignar usando la única relación posible: import_id → buscar id_lead del primer lead del import
            // Para registros donde haya 1 lead por import es exacto; para múltiples es aproximado
            $aff = $db->exec("
                UPDATE import_log_leads_respuestas r
                JOIN (
                    SELECT import_id, MIN(id_lead) AS id_lead
                    FROM import_log_leads
                    GROUP BY import_id
                ) lmin ON lmin.import_id = r.import_id
                SET r.lead_id = lmin.id_lead
                WHERE r.lead_id IS NULL
            ");
            step($log, "  ⚠️  lead_id poblado vía import_id (atribución individual perdida): <b>" . number_format($aff) . "</b>");

            $db->exec("ALTER TABLE import_log_leads_respuestas
                       MODIFY COLUMN lead_id INT NOT NULL");
        }
    }

    // ── Drop import_id ───────────────────────────────────────────
    if (hasCol($db, 'import_log_leads_respuestas', 'import_id')) {
        dropFksOnCol($db, 'import_log_leads_respuestas', 'import_id', $log);
        dropIdxOnCol($db, 'import_log_leads_respuestas', 'import_id', $log);
        $db->exec("ALTER TABLE import_log_leads_respuestas DROP COLUMN import_id");
        step($log, "  🗑️  import_id eliminado");
    }

    // ── Índice + FK final ────────────────────────────────────────
    $existingIdxOnLead = $db->query(
        "SHOW INDEX FROM import_log_leads_respuestas WHERE Column_name='lead_id' AND Key_name='idx_resp_lead'"
    )->fetchColumn();
    if (!$existingIdxOnLead) {
        $db->exec("ALTER TABLE import_log_leads_respuestas ADD INDEX idx_resp_lead (lead_id)");
        step($log, "  ✅ Índice idx_resp_lead añadido");
    }

    $existingFk = $db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='import_log_leads_respuestas'
           AND CONSTRAINT_NAME='fk_resp_id_lead' AND CONSTRAINT_TYPE='FOREIGN KEY'"
    )->fetchColumn();
    if (!$existingFk) {
        $db->exec("ALTER TABLE import_log_leads_respuestas
                   ADD CONSTRAINT fk_resp_id_lead
                   FOREIGN KEY (lead_id) REFERENCES import_log_leads(id_lead) ON DELETE CASCADE");
        step($log, "  ✅ FK fk_resp_id_lead añadida");
    }

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
}

// Schema resultante
step($log, "<strong>── Schema resultante: import_log_leads_respuestas ──</strong>");
foreach ($db->query("DESCRIBE import_log_leads_respuestas")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $null = $c['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
    $key  = $c['Key'] ? " <span style='color:#f59e0b'>[{$c['Key']}]</span>" : '';
    step($log, "  &nbsp;<code>{$c['Field']}</code> {$c['Type']} $null$key");
}
$nResp = (int)$db->query("SELECT COUNT(*) FROM import_log_leads_respuestas")->fetchColumn();
step($log, "  📊 Respuestas: <b>" . number_format($nResp) . "</b>");

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// PASO 3 — leads_ids en import_log_leads_preguntas
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
step($log, "<strong>── PASO 3: leads_ids en import_log_leads_preguntas ──</strong>");

// Verificar que lead_id existe en respuestas antes de continuar
if (!hasCol($db, 'import_log_leads_respuestas', 'lead_id')) {
    step($log, "❌ lead_id no existe en respuestas — PASO 3 abortado, revisa PASO 2");
} else {

    if (!hasCol($db, 'import_log_leads_preguntas', 'leads_ids')) {
        // Añadir después de import_ids si existe, si no al final
        $afterClause = hasCol($db, 'import_log_leads_preguntas', 'import_ids') ? 'AFTER import_ids' : '';
        $db->exec("ALTER TABLE import_log_leads_preguntas
                   ADD COLUMN leads_ids JSON NULL
                   COMMENT 'Array de id_lead (PK numérico) que respondieron esta pregunta'
                   $afterClause");
        step($log, "  ✅ Columna leads_ids añadida");
    } else {
        step($log, "  ⏭️  leads_ids ya existe — repoblando");
    }

    $db->exec("SET SESSION group_concat_max_len = 10000000");
    $aff3 = $db->exec("
        UPDATE import_log_leads_preguntas p
        JOIN (
            SELECT pregunta_id,
                   CONCAT('[', GROUP_CONCAT(DISTINCT lead_id ORDER BY lead_id SEPARATOR ','), ']') AS ids
            FROM import_log_leads_respuestas
            GROUP BY pregunta_id
        ) agg ON agg.pregunta_id = p.id
        SET p.leads_ids = agg.ids
    ");
    step($log, "  ✅ leads_ids poblado en <b>$aff3</b> preguntas");

    // Drop import_ids si aún existe
    if (hasCol($db, 'import_log_leads_preguntas', 'import_ids')) {
        $db->exec("ALTER TABLE import_log_leads_preguntas DROP COLUMN import_ids");
        step($log, "  🗑️  import_ids eliminado");
    }

    // Muestra
    step($log, "<strong>── Muestra leads_ids ──</strong>");
    foreach ($db->query("
        SELECT id, SUBSTR(pregunta,1,70) AS preg,
               JSON_LENGTH(leads_ids) AS n,
               SUBSTR(leads_ids,1,60) AS preview
        FROM import_log_leads_preguntas
        WHERE leads_ids IS NOT NULL
        ORDER BY JSON_LENGTH(leads_ids) DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        step($log, "  <b>[{$r['id']}]</b> {$r['preg']}… <span style='color:#f59e0b'>{$r['n']} leads</span> <code style='color:#6366f1'>{$r['preview']}</code>");
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// PASO 4 — Normalizar columnas de texto en import_log_leads
//   · _ → espacio  · TRIM  · colapsar espacios múltiples
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
step($log, "<strong>── PASO 4: Normalizar texto en import_log_leads ──</strong>");

$textCols = [
    'nombre', 'email', 'telefono',
    'ad_name', 'adset_name', 'campaign_name', 'form_name', 'platform',
    'sexo', 'rango_edad', 'afiliacion_politica', 'nivel_educativo',
    'geo_departamento', 'subregion', 'municipio', 'zona', 'comuna_localidad', 'barrio',
];

$totalAff4 = 0;
foreach ($textCols as $col) {
    if (!hasCol($db, 'import_log_leads', $col)) {
        step($log, "  ⏭️  <code>$col</code> no existe — omitida");
        continue;
    }
    $aff = $db->exec("
        UPDATE import_log_leads
        SET `$col` = TRIM(REGEXP_REPLACE(REPLACE(`$col`, '_', ' '), '  +', ' '))
        WHERE `$col` IS NOT NULL
    ");
    $totalAff4 += $aff;
    if ($aff > 0) step($log, "  ✅ <code>$col</code>: <b>$aff</b> filas");
}
step($log, "  📊 Total filas modificadas en texto: <b>$totalAff4</b>");

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// VERIFICACIÓN FINAL
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
step($log, "<strong>── Verificación de integridad ──</strong>");

$checks = [
    ['respuestas.lead_id → import_log_leads.id_lead',
     "SELECT COUNT(*) FROM import_log_leads_respuestas r
      LEFT JOIN import_log_leads l ON l.id_lead = r.lead_id WHERE l.id_lead IS NULL"],
    ['import_log_leads.import_id → import_log.id',
     "SELECT COUNT(*) FROM import_log_leads ll
      LEFT JOIN import_log il ON il.id = ll.import_id WHERE il.id IS NULL"],
    ['respuestas.pregunta_id → preguntas.id',
     "SELECT COUNT(*) FROM import_log_leads_respuestas r
      LEFT JOIN import_log_leads_preguntas p ON p.id = r.pregunta_id WHERE p.id IS NULL"],
];
foreach ($checks as [$label, $sql]) {
    try {
        $n = (int)$db->query($sql)->fetchColumn();
        step($log, ($n === 0 ? '✅' : '❌') . " $label: <b>$n huérfanas</b>");
    } catch (\Exception $e) {
        step($log, "⚠️  $label: no verificable (" . $e->getMessage() . ")");
    }
}

step($log, "<strong>── Conteos finales ──</strong>");
foreach ([
    'import_log'                  => 'Imports',
    'import_log_leads'            => 'Leads',
    'import_log_leads_respuestas' => 'Respuestas EAV',
    'import_log_leads_preguntas'  => 'Catálogo preguntas',
] as $tbl => $label) {
    $n = (int)$db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
    step($log, "  $label: <b>" . number_format($n) . "</b>");
}

step($log, "<strong>⏱️  " . round(microtime(true)-$t0, 2) . "s</strong>");
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><title>leads · migración + normalización</title>
<style>
body{font-family:'Segoe UI',monospace;background:#0f1117;color:#e8eaf0;padding:30px;line-height:2}
h2{color:#6366f1;margin-bottom:4px}
.sub{color:#7b84a3;font-size:.82rem;margin-bottom:20px}
.log{background:#1e2132;border:1px solid #2d3148;border-radius:8px;padding:20px;max-width:1200px;font-size:.83rem}
.log p{margin:1px 0;padding:2px 0;border-bottom:1px solid rgba(255,255,255,.04)}
code{background:#0f1117;padding:1px 5px;border-radius:3px;color:#a5b4fc}
</style>
</head>
<body>
<h2>🔑 GET · Leads · Migración + Normalización</h2>
<p class="sub">Schema (id_lead, FK, leads_ids) → Texto (_ → espacio · trim) · Idempotente</p>
<div class="log">
<?php foreach ($log as $line): ?><p><?= $line ?></p><?php endforeach; ?>
</div>
<p style="margin-top:12px;color:#7b84a3;font-size:.78rem">
  <a href="/medfut/leads_analytics.html" style="color:#6366f1">← Dashboard</a>
</p>
</body></html>
