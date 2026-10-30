<?php
/**
 * _geo2_migrate.php
 * Migra import_log_leads.id_geo de INT (→ C98_GET.dim_geo)
 * a VARCHAR(15) (→ c98_geo DANE codes: barrio/zona/municipio)
 *
 * Fases:
 *   1. ADD COLUMN id_geo_c98 VARCHAR(15) (si no existe)
 *   2. Poblar id_geo_c98 vía PHP maps desde c98_geo
 *   3. Swap: DROP id_geo, RENAME id_geo_c98 → id_geo
 *   4. (manual) DROP C98_GET.dim_geo
 *
 * ?step=1  → Solo ADD COLUMN
 * ?step=2  → Solo poblar (dry-run por defecto)
 * ?step=2&apply=1 → Poblar y aplicar UPDATEs
 * ?step=3  → Swap columnas (confirma en modal)
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
set_time_limit(300);

$pdo   = getDB();
$step  = (int)($_GET['step']  ?? 0);
$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

/* ── Helpers ───────────────────────────── */
function norm(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
                       'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ñ'=>'N']);
}

/* ── Estado de columnas ────────────────── */
function colExists(\PDO $pdo, string $col): bool {
    $r = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='C98_GET' AND TABLE_NAME='import_log_leads' AND COLUMN_NAME='$col'")->fetchColumn();
    return $r > 0;
}

$hasOldGeo  = colExists($pdo, 'id_geo');       // INT original (puede ser int o varchar ya)
$hasNewGeo  = colExists($pdo, 'id_geo_c98');   // VARCHAR trabajo
$oldGeoType = '';
if ($hasOldGeo) {
    $oldGeoType = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='C98_GET' AND TABLE_NAME='import_log_leads' AND COLUMN_NAME='id_geo'")->fetchColumn();
}

/* ── Cobertura actual ──────────────────── */
$stats = $pdo->query("
    SELECT
        COUNT(*) total,
        SUM(id_geo IS NOT NULL) has_old_geo,
        " . ($hasNewGeo ? "SUM(id_geo_c98 IS NOT NULL) has_new_geo," : "0 has_new_geo,") . "
        SUM(id_geo IS NULL) no_geo
    FROM import_log_leads
")->fetch(PDO::FETCH_ASSOC);

$msgs = [];

/* ═══════════════════════════════════════════
   STEP 1 — ADD COLUMN id_geo_c98 VARCHAR(15)
═══════════════════════════════════════════ */
if ($step === 1) {
    if ($hasNewGeo) {
        $msgs[] = ['ok'=>true, 'msg'=>'id_geo_c98 ya existe — nada que hacer.'];
    } else {
        $pdo->exec("ALTER TABLE import_log_leads ADD COLUMN id_geo_c98 VARCHAR(15) NULL DEFAULT NULL AFTER id_geo");
        $pdo->exec("CREATE INDEX idx_ill_id_geo_c98 ON import_log_leads(id_geo_c98)");
        $msgs[] = ['ok'=>true, 'msg'=>'✅ Columna id_geo_c98 VARCHAR(15) creada + índice.'];
        $hasNewGeo = true;
    }
}

/* ═══════════════════════════════════════════
   STEP 2 — Poblar id_geo_c98 desde c98_geo
═══════════════════════════════════════════ */
$planResult = [];
if ($step === 2) {
    if (!$hasNewGeo) {
        $msgs[] = ['ok'=>false, 'msg'=>'⛔ Primero ejecuta Step 1 (ADD COLUMN).'];
    } else {
        /* Cargar maps de c98_geo */
        $mpioMap = [];
        foreach ($pdo->query("SELECT id, nombre, abreviatura FROM c98_geo.dim_municipio")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mpioMap[norm($r['nombre'])] = $r['id'];
            if ($r['abreviatura']) $mpioMap[norm($r['abreviatura'])] = $r['id'];
        }

        $zonaMap = [];
        foreach ($pdo->query("SELECT id, nombre, id_municipio FROM c98_geo.dim_zona")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $zonaMap[norm($r['nombre']).'||'.$r['id_municipio']] = $r['id'];
        }

        $comunaMap = [];
        foreach ($pdo->query("
            SELECT c.id, c.nombre, z.id_municipio
            FROM c98_geo.dim_comuna c JOIN c98_geo.dim_zona z ON z.id=c.id_zona
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $comunaMap[norm($r['nombre']).'||'.$r['id_municipio']] = $r['id'];
        }

        $barrioMap = [];
        foreach ($pdo->query("
            SELECT b.id, b.nombre, z.id_municipio
            FROM c98_geo.dim_barrio b
            JOIN c98_geo.dim_comuna c ON c.id=b.id_comuna
            JOIN c98_geo.dim_zona   z ON z.id=c.id_zona
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $barrioMap[norm($r['nombre']).'||'.$r['id_municipio']] = $r['id'];
        }

        /* Iterar cada id_geo distinto con sus nombres */
        $rows = $pdo->query("
            SELECT g.id AS old_id, g.nivel,
                   g.nom_mpio, g.nom_barrio, g.nom_zona, g.nom_comuna,
                   COUNT(l.id_lead) n,
                   GROUP_CONCAT(l.id_lead ORDER BY l.id_lead SEPARATOR ',') ids
            FROM C98_GET.dim_geo g
            JOIN C98_GET.import_log_leads l ON l.id_geo = g.id AND l.id_geo_c98 IS NULL
            GROUP BY g.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $phaseCount = ['barrio'=>0,'comuna'=>0,'zona'=>0,'mpio'=>0,'miss'=>0];
        $phaseLeads = ['barrio'=>0,'comuna'=>0,'zona'=>0,'mpio'=>0,'miss'=>0];
        $applied = 0;

        foreach ($rows as $g) {
            $normMpio   = norm($g['nom_mpio']   ?? '');
            $normBarrio = norm($g['nom_barrio'] ?? '');
            $normZona   = norm($g['nom_zona']   ?? '');
            $normComuna = norm($g['nom_comuna'] ?? '');
            $n = (int)$g['n'];

            $mpioId = $mpioMap[$normMpio] ?? null;
            $newId  = null;
            $phase  = 'miss';

            if ($mpioId) {
                // P1 — Barrio
                if ($normBarrio) {
                    $k = $normBarrio.'||'.$mpioId;
                    if (isset($barrioMap[$k])) { $newId = $barrioMap[$k]; $phase = 'barrio'; }
                }
                // P2 — Comuna
                if (!$newId && $normComuna) {
                    $k = $normComuna.'||'.$mpioId;
                    if (isset($comunaMap[$k])) { $newId = $comunaMap[$k]; $phase = 'comuna'; }
                }
                // P2b — Zona
                if (!$newId && $normZona) {
                    $k = $normZona.'||'.$mpioId;
                    if (isset($zonaMap[$k])) { $newId = $zonaMap[$k]; $phase = 'zona'; }
                }
                // P3 — Municipio
                if (!$newId) { $newId = $mpioId; $phase = 'mpio'; }
            }

            $phaseCount[$phase]++;
            $phaseLeads[$phase] += $n;

            if ($newId && $apply) {
                $allIds = $g['ids'];
                $cnt = $pdo->exec("UPDATE import_log_leads SET id_geo_c98='$newId' WHERE id_lead IN ($allIds)");
                $applied += $cnt;
            }

            $planResult[] = [
                'old_id' => $g['old_id'],
                'new_id' => $newId,
                'phase'  => $phase,
                'n'      => $n,
                'mpio'   => $g['nom_mpio'],
                'barrio' => $g['nom_barrio'],
            ];
        }

        if ($apply) {
            $msgs[] = ['ok'=>true, 'msg'=>"✅ $applied leads actualizados con id_geo_c98."];
        } else {
            $msgs[] = ['ok'=>true, 'msg'=>"Dry-run: " . array_sum($phaseLeads) . " leads analizados. Usa ?step=2&apply=1 para aplicar."];
        }

        $planResult = ['phases'=>$phaseCount, 'leads'=>$phaseLeads, 'apply'=>$apply, 'applied'=>$applied ?? 0];
    }
}

/* ═══════════════════════════════════════════
   STEP 3 — Swap: DROP id_geo, RENAME id_geo_c98
═══════════════════════════════════════════ */
if ($step === 3 && $apply) {
    if (!$hasNewGeo) {
        $msgs[] = ['ok'=>false,'msg'=>'⛔ id_geo_c98 no existe. Ejecuta Steps 1 y 2 primero.'];
    } elseif ($oldGeoType !== 'int') {
        $msgs[] = ['ok'=>false,'msg'=>"⛔ id_geo ya es $oldGeoType — swap ya realizado o estado inesperado."];
    } else {
        // Verificar cobertura mínima antes de swap
        $cov = $pdo->query("SELECT SUM(id_geo_c98 IS NOT NULL)/COUNT(*)*100 FROM import_log_leads WHERE id_geo IS NOT NULL")->fetchColumn();
        if ($cov < 95) {
            $msgs[] = ['ok'=>false,'msg'=>"⛔ Cobertura de id_geo_c98 es ".round($cov,1)."% — necesitas ≥95% antes del swap."];
        } else {
            // 1. Eliminar FK constraints sobre id_geo (si existen)
            $fks = $pdo->query("
                SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA='C98_GET' AND TABLE_NAME='import_log_leads'
                  AND COLUMN_NAME='id_geo' AND REFERENCED_TABLE_NAME IS NOT NULL
            ")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($fks as $fk) {
                $pdo->exec("ALTER TABLE import_log_leads DROP FOREIGN KEY `$fk`");
            }
            // 2. Eliminar índice sobre id_geo (si no lo elimina el DROP COLUMN automáticamente)
            // 3. SWAP: DROP + CHANGE
            $pdo->exec("ALTER TABLE import_log_leads
                DROP COLUMN id_geo,
                CHANGE COLUMN id_geo_c98 id_geo VARCHAR(15) NULL DEFAULT NULL");
            $msgs[] = ['ok'=>true,'msg'=>"✅ SWAP completado: id_geo ahora es VARCHAR(15) con códigos c98_geo."];
            $msgs[] = ['ok'=>true,'msg'=>"Siguiente: DROP TABLE C98_GET.dim_geo (cuando estés listo)."];
            // Refrescar estado
            $hasOldGeo  = colExists($pdo, 'id_geo');
            $hasNewGeo  = colExists($pdo, 'id_geo_c98');
            $oldGeoType = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA='C98_GET' AND TABLE_NAME='import_log_leads' AND COLUMN_NAME='id_geo'")->fetchColumn();
        }
    }
}

/* ── Refrescar stats ─── */
$stats = $pdo->query("
    SELECT
        COUNT(*) total,
        SUM(id_geo IS NOT NULL) has_old_geo,
        " . ($hasNewGeo ? "SUM(id_geo_c98 IS NOT NULL) has_new_geo," : "0 has_new_geo,") . "
        SUM(id_geo IS NULL) no_geo
    FROM import_log_leads
")->fetch(PDO::FETCH_ASSOC);

?><!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Migración geo2</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:monospace;font-size:13px;background:#0f172a;color:#e2e8f0;padding:24px}
h1{color:#38bdf8;font-size:17px;margin-bottom:16px}
h2{color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin:20px 0 8px}
.row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.kpi{background:#1e293b;border-radius:8px;padding:12px 20px;text-align:center;flex:1;min-width:120px}
.kpi-val{font-size:22px;font-weight:700}.kpi-lbl{font-size:10px;color:#64748b;text-transform:uppercase;margin-top:2px}
.card{background:#1e293b;border-radius:8px;padding:14px 18px;margin-bottom:12px}
table{border-collapse:collapse;width:100%;font-size:12px}
th{background:#0f172a;color:#64748b;padding:6px 10px;text-align:left;border-bottom:1px solid #334155;font-size:10px;text-transform:uppercase}
td{padding:5px 10px;border-bottom:1px solid #1e293b}
.ok{color:#4ade80}.warn{color:#facc15}.err{color:#f87171}.info{color:#60a5fa}
.btn{display:inline-block;padding:9px 22px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;margin:3px}
.btn-go{background:#0ea5e9;color:#fff}.btn-dry{background:#334155;color:#cbd5e1}
.btn-warn{background:#f59e0b;color:#000}.btn-red{background:#ef4444;color:#fff}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
.prog{height:8px;border-radius:4px;background:#0f172a;overflow:hidden;margin-top:4px}
.prog-bar{height:100%;border-radius:4px}
.step{background:#1e293b;border-radius:8px;padding:14px 18px;margin-bottom:10px;border-left:3px solid #334155}
.step.done{border-left-color:#22c55e}.step.active{border-left-color:#0ea5e9}
</style>
</head>
<body>
<h1>⚙️ Migración id_geo → c98_geo DANE codes</h1>

<!-- Estado de columnas -->
<h2>Estado actual</h2>
<div class="row">
  <div class="kpi">
    <div class="kpi-val <?= $hasOldGeo ? ($oldGeoType==='int'?'warn':'ok') : 'err' ?>">
      <?= $hasOldGeo ? strtoupper($oldGeoType) : 'NO EXISTE' ?>
    </div>
    <div class="kpi-lbl">id_geo (tipo)</div>
  </div>
  <div class="kpi">
    <div class="kpi-val <?= $hasNewGeo ? 'ok' : 'err' ?>">
      <?= $hasNewGeo ? 'VARCHAR(15)' : 'NO EXISTE' ?>
    </div>
    <div class="kpi-lbl">id_geo_c98</div>
  </div>
  <div class="kpi">
    <div class="kpi-val"><?= number_format($stats['has_old_geo']) ?></div>
    <div class="kpi-lbl">Con id_geo</div>
  </div>
  <div class="kpi">
    <div class="kpi-val <?= $hasNewGeo ? 'ok' : 'info' ?>"><?= $hasNewGeo ? number_format($stats['has_new_geo']) : '—' ?></div>
    <div class="kpi-lbl">Con id_geo_c98</div>
  </div>
</div>

<!-- Mensajes -->
<?php foreach ($msgs as $m): ?>
<div class="card"><span class="<?= $m['ok']?'ok':'err' ?>"><?= htmlspecialchars($m['msg']) ?></span></div>
<?php endforeach; ?>

<!-- Resultados de step 2 -->
<?php if ($step===2 && !empty($planResult)): ?>
<h2>Distribución del mapeo</h2>
<div class="card">
<table>
<tr><th>Fase</th><th>id_geo distintos</th><th>Leads</th></tr>
<?php
$phases = [
  'barrio' => ['label'=>'P1 — Barrio (nivel=7)','color'=>'#86efac'],
  'comuna' => ['label'=>'P2 — Comuna (nivel=6)','color'=>'#facc15'],
  'zona'   => ['label'=>'P2b — Zona (nivel=5)','color'=>'#c084fc'],
  'mpio'   => ['label'=>'P3 — Municipio (nivel=4)','color'=>'#fb923c'],
  'miss'   => ['label'=>'Sin match','color'=>'#f87171'],
];
foreach ($phases as $ph => $info): $c=$planResult['phases'][$ph]; $l=$planResult['leads'][$ph]; ?>
<tr>
  <td><span class="badge" style="background:<?=$info['color']?>22;color:<?=$info['color']?>"><?=$info['label']?></span></td>
  <td><?=number_format($c)?></td>
  <td class="<?=$ph==='miss'&&$l>0?'err':'ok'?>"><?=number_format($l)?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php if ($planResult['apply']): ?>
<div class="card ok">✅ <?= number_format($planResult['applied']) ?> leads actualizados.</div>
<?php endif; ?>
<?php endif; ?>

<!-- Steps de navegación -->
<h2>Pasos de migración</h2>

<div class="step <?= $hasNewGeo ? 'done' : 'active' ?>">
  <b>Step 1 — ADD COLUMN id_geo_c98 VARCHAR(15)</b><br>
  <small class="<?= $hasNewGeo?'ok':'warn' ?>"><?= $hasNewGeo?'✅ Completado':'⏳ Pendiente' ?></small><br><br>
  <?php if (!$hasNewGeo): ?>
  <a class="btn btn-go" href="?step=1">▶ Ejecutar Step 1</a>
  <?php endif; ?>
</div>

<div class="step <?= ($hasNewGeo && (int)$stats['has_new_geo']>0) ? 'done' : ($hasNewGeo?'active':'') ?>">
  <b>Step 2 — Poblar id_geo_c98 desde c98_geo</b><br>
  <?php if ($hasNewGeo): ?>
    <small class="info">Poblado: <?= number_format($stats['has_new_geo']) ?> / <?= number_format($stats['has_old_geo']) ?></small>
    <?php if ((int)$stats['has_new_geo'] < (int)$stats['has_old_geo']): ?>
    <br><br>
    <a class="btn btn-dry" href="?step=2">🔍 Dry-run</a>
    <a class="btn btn-go" href="?step=2&apply=1">▶ Aplicar</a>
    <?php else: ?>
    <small class="ok"> — ✅ Completo</small>
    <?php endif; ?>
  <?php else: ?>
  <small class="err">Requiere Step 1 primero.</small>
  <?php endif; ?>
</div>

<div class="step <?= ($hasOldGeo && $oldGeoType!=='int') ? 'done' : '' ?>">
  <b>Step 3 — Swap columnas (DROP id_geo INT, RENAME id_geo_c98 → id_geo)</b><br>
  <?php if ($hasOldGeo && $oldGeoType==='int' && $hasNewGeo && (int)$stats['has_new_geo']>0): ?>
  <small class="warn">⚠ IRREVERSIBLE — asegúrate que Step 2 esté completo.</small><br><br>
  <a class="btn btn-red" href="?step=3&apply=1"
     onclick="return confirm('¿CONFIRMAR SWAP? DROP id_geo (INT) + RENAME id_geo_c98 → id_geo. Irreversible.')">
    ⚠ SWAP columnas
  </a>
  <?php elseif ($hasOldGeo && $oldGeoType!=='int'): ?>
  <small class="ok">✅ Completado — id_geo ya es <?= $oldGeoType ?></small>
  <?php else: ?>
  <small class="err">Requiere Steps 1 y 2 primero.</small>
  <?php endif; ?>
</div>

<div class="step">
  <b>Step 4 — DROP TABLE C98_GET.dim_geo</b><br>
  <small class="<?= ($hasOldGeo&&$oldGeoType!=='int')?'warn':'err' ?>">
    <?= ($hasOldGeo&&$oldGeoType!=='int') ? '⏳ Listo para eliminar — ejecuta cuando confirmes que todo funciona.' : 'Requiere Steps 1-3 primero.' ?>
  </small>
</div>

</body>
</html>
