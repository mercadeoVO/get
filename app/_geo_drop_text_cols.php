<?php
/**
 * _geo_drop_text_cols.php
 * Elimina las columnas de texto geográfico de import_log_leads
 * DESPUÉS de confirmar que id_geo tiene cobertura suficiente.
 *
 * Columnas a eliminar:
 *   geo_departamento, subregion, municipio, zona, comuna_localidad, barrio
 *
 * PREREQUISITO: correr _geo_migrate_id_geo.php?apply=1 primero.
 * Usar ?drop=1 para ejecutar. Por defecto muestra resumen de cobertura.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$pdo  = getDB();
$drop = isset($_GET['drop']) && $_GET['drop'] === '1';

$COLS_TO_DROP = ['geo_departamento','subregion','municipio','zona','comuna_localidad','barrio'];

/* ── Cobertura actual ─────────────────────────────── */
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(id_geo IS NOT NULL) AS n_id_geo,
        SUM(id_geo IS NULL)     AS n_null
    FROM import_log_leads
")->fetch(\PDO::FETCH_ASSOC);

$total     = (int)$stats['total'];
$matched   = (int)$stats['n_id_geo'];
$nullCount = (int)$stats['n_null'];
$coverage  = $total > 0 ? round($matched / $total * 100, 2) : 0;

/* ── Verificar que las columnas existen ──────────── */
$existingCols = $pdo->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_log_leads'
      AND COLUMN_NAME IN ('geo_departamento','subregion','municipio','zona','comuna_localidad','barrio')
")->fetchAll(\PDO::FETCH_COLUMN);

$colsExist = array_intersect($COLS_TO_DROP, $existingCols);

/* ── Aplicar DROP ─────────────────────────────────── */
$dropResults = [];
$dropOk      = false;

if ($drop) {
    if ($coverage < 70) {
        $dropOk = false;
        $dropResults[] = ['col'=>'—','ok'=>false,'msg'=>"Cobertura de id_geo ($coverage%) es < 70%. Abortado. Corre la migración primero."];
    } elseif (empty($colsExist)) {
        $dropOk = false;
        $dropResults[] = ['col'=>'—','ok'=>false,'msg'=>'Las columnas ya no existen — nada que hacer.'];
    } else {
        $dropSql = "ALTER TABLE import_log_leads DROP COLUMN " .
            implode(', DROP COLUMN ', $colsExist);
        try {
            $pdo->exec($dropSql);
            $dropOk = true;
            foreach ($colsExist as $c)
                $dropResults[] = ['col'=>$c,'ok'=>true,'msg'=>'Eliminada'];
        } catch (\Exception $e) {
            $dropResults[] = ['col'=>implode(',',$colsExist),'ok'=>false,'msg'=>$e->getMessage()];
        }
    }
}

/* ── Muestreo de leads aún sin id_geo ─────────────── */
// Columna municipio eliminada — ya no disponible post-DROP
$nullSample = [];

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Drop Text Geo Cols</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:monospace;font-size:13px;background:#0f172a;color:#e2e8f0;padding:24px}
h1{color:#38bdf8;font-size:17px;margin-bottom:16px}
h2{color:#94a3b8;font-size:12px;text-transform:uppercase;letter-spacing:.06em;margin:20px 0 8px}
.card{background:#1e293b;border-radius:8px;padding:14px 18px;margin-bottom:12px}
.row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.kpi{background:#1e293b;border-radius:8px;padding:12px 20px;text-align:center;flex:1;min-width:130px}
.kpi-val{font-size:26px;font-weight:700}
.kpi-lbl{font-size:11px;color:#64748b;text-transform:uppercase;margin-top:2px}
table{border-collapse:collapse;width:100%;font-size:12px}
th{background:#0f172a;color:#64748b;padding:6px 10px;text-align:left;border-bottom:1px solid #334155;text-transform:uppercase;font-size:10px}
td{padding:6px 10px;border-bottom:1px solid #1e293b}
.ok{color:#4ade80} .warn{color:#facc15} .err{color:#f87171} .info{color:#60a5fa}
.btn{display:inline-block;padding:9px 22px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;margin:4px}
.btn-drop{background:#ef4444;color:#fff}
.btn-mig {background:#0ea5e9;color:#fff}
.btn-dry {background:#334155;color:#cbd5e1}
.prog{height:10px;border-radius:5px;background:#0f172a;overflow:hidden;margin-top:6px}
.prog-bar{height:100%;border-radius:5px}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;
       background:rgba(239,68,68,.15);color:#f87171}
</style>
</head>
<body>
<h1>🗑 Eliminar columnas de texto geográfico</h1>

<!-- Estado de cobertura -->
<h2>Cobertura id_geo actual</h2>
<div class="row">
  <div class="kpi"><div class="kpi-val"><?= number_format($total) ?></div><div class="kpi-lbl">Total leads</div></div>
  <div class="kpi"><div class="kpi-val ok"><?= number_format($matched) ?></div><div class="kpi-lbl">Con id_geo</div></div>
  <div class="kpi"><div class="kpi-val <?= $nullCount > 0 ? 'warn' : 'ok' ?>"><?= number_format($nullCount) ?></div><div class="kpi-lbl">Sin id_geo</div></div>
  <div class="kpi"><div class="kpi-val <?= $coverage >= 70 ? 'ok' : 'err' ?>"><?= $coverage ?>%</div><div class="kpi-lbl">Cobertura</div></div>
</div>
<div class="card">
  <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px">
    <span>Cobertura id_geo</span>
    <span class="<?= $coverage >= 70 ? 'ok' : 'err' ?>"><?= $coverage ?>%</span>
  </div>
  <div class="prog">
    <div class="prog-bar" style="width:<?= $coverage ?>%;background:<?= $coverage>=70?'#22c55e':'#ef4444' ?>"></div>
  </div>
  <?php if ($coverage < 70): ?>
  <p class="err" style="margin-top:8px;font-size:11px">
    ⚠ Cobertura insuficiente. Corre <a href="_geo_migrate_id_geo.php?apply=1" class="info">la migración</a> primero.
  </p>
  <?php endif; ?>
</div>

<!-- Columnas que existen -->
<h2>Columnas a eliminar</h2>
<div class="card">
<?php foreach ($COLS_TO_DROP as $c): ?>
  <span class="badge" style="margin:3px"><?= $c ?></span>
<?php endforeach; ?>
  <?php if (empty($colsExist)): ?>
  <p class="ok" style="margin-top:8px">✅ Todas ya eliminadas.</p>
  <?php endif; ?>
</div>

<!-- Acciones -->
<div class="card">
  <?php if ($drop && !empty($dropResults)): ?>
    <?php foreach ($dropResults as $r): ?>
    <p class="<?= $r['ok'] ? 'ok' : 'err' ?>">
      <?= $r['ok'] ? '✅' : '❌' ?> <?= htmlspecialchars($r['col']) ?>: <?= htmlspecialchars($r['msg']) ?>
    </p>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!$drop || !$dropOk): ?>
  <p style="margin-top:8px">
    <a class="btn btn-mig" href="_geo_migrate_id_geo.php">Ver migración</a>
    <?php if ($coverage >= 70 && !empty($colsExist)): ?>
    <a class="btn btn-drop" href="?drop=1"
       onclick="return confirm('¿Eliminar permanentemente geo_departamento, subregion, municipio, zona, comuna_localidad, barrio de import_log_leads? Esta acción no se puede deshacer.')">
      ⚠ Eliminar <?= count($colsExist) ?> columnas
    </a>
    <?php endif; ?>
  </p>
  <?php else: ?>
  <p class="ok" style="margin-top:8px">✅ DROP ejecutado correctamente.</p>
  <p style="margin-top:8px;font-size:11px;color:#64748b">
    Siguiente paso: actualizar <code>leads_analytics.html</code> y <code>c98geo_api.php</code>
    para usar JOIN con dim_geo en lugar de las columnas eliminadas.
  </p>
  <?php endif; ?>
</div>

<!-- Muestra de sin match -->
<?php if (!empty($nullSample)): ?>
<h2>Municipios sin match (top 20 — quedarán sin id_geo)</h2>
<table>
<tr><th>municipio (en lead)</th><th>Leads afectados</th></tr>
<?php foreach ($nullSample as $r): ?>
<tr>
  <td><?= htmlspecialchars($r['municipio']) ?></td>
  <td class="warn"><?= number_format($r['n']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

</body>
</html>
