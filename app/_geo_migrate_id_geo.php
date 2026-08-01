<?php
/**
 * _geo_migrate_id_geo.php  (v2 — PHP-loop, sin JOINs con REPLACE)
 * Poblar import_log_leads.id_geo desde dim_geo, cascada granular.
 *
 * Estrategia rápida:
 *   1. Carga dim_geo en PHP → maps normalizados
 *   2. Itera municipios/comunas/barrios distintos en leads → busca en maps
 *   3. UPDATE por id_geo exacto (sin JOIN costoso)
 *
 * ?apply=1  → ejecutar    (por defecto dry-run)
 * ?reset=1  → id_geo=NULL en todos (dev)
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
set_time_limit(300);

$pdo   = getDB();
$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$reset = isset($_GET['reset']) && $_GET['reset'] === '1';

/* ── Helpers ─────────────────────────────── */
function phpNorm(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
                       'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ñ'=>'N']);
}
function cl(\PDO $p, string $w = '1'): int {
    return (int)$p->query("SELECT COUNT(*) FROM import_log_leads WHERE $w")->fetchColumn();
}

/* ── Reset ───────────────────────────────── */
if ($reset) {
    $pdo->exec("UPDATE import_log_leads SET id_geo = NULL");
    echo "<p style='color:#f87171'>⚠ id_geo reseteado.</p>";
}

/* ── Cargar dim_geo en PHP ───────────────── */
// Alias de municipios: clave normalizada del lead → nom_mpio en dim_geo
const MPIO_ALIASES = [
    'MANIZALEZ'          => 'MANIZALES',       // typo
    'RIOACHA'            => 'RIOHACHA',         // typo
    'SAN ANDRES ISLAS'   => 'SAN ANDRES',
    'SANTA FE DE BOGOTA' => 'BOGOTA',
    'BOGOTA D.C.'        => 'BOGOTA',
    'BOGOTA DC'          => 'BOGOTA',
];

// Map nivel=4 (municipio): normMpio → id
$mpioMap = [];
foreach ($pdo->query("SELECT id, nom_mpio FROM dim_geo WHERE nivel=4")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mpioMap[phpNorm($r['nom_mpio'])] = (int)$r['id'];
}

// Map nivel=6 (comuna): normComuna||normMpio → id
$comunaMap = [];
foreach ($pdo->query("SELECT id, nom_mpio, nom_comuna FROM dim_geo WHERE nivel=6")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = phpNorm($r['nom_comuna']) . '||' . phpNorm($r['nom_mpio']);
    $comunaMap[$key] = (int)$r['id'];
}

// Map nivel=7 (barrio): normBarrio||normZona||normMpio → id
$barrioMap = [];
foreach ($pdo->query("SELECT id, nom_mpio, nom_zona, nom_barrio FROM dim_geo WHERE nivel=7")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = phpNorm($r['nom_barrio']) . '||' . phpNorm($r['nom_zona']) . '||' . phpNorm($r['nom_mpio']);
    $barrioMap[$key] = (int)$r['id'];
}

/* ── Estado inicial ──────────────────────── */
$total      = cl($pdo);
$preMatched = cl($pdo, 'id_geo IS NOT NULL');

/* ── Resolver id_geo por combinación distinta ── */
// Fetch todas las combinaciones únicas de barrio+zona+mpio+comuna+localidad
$rows = $pdo->query("
    SELECT barrio, zona, municipio, comuna_localidad,
           COUNT(*) n,
           GROUP_CONCAT(id_lead ORDER BY id_lead SEPARATOR ',') ids
    FROM import_log_leads
    WHERE id_geo IS NULL
    GROUP BY barrio, zona, municipio, comuna_localidad
")->fetchAll(PDO::FETCH_ASSOC);

$plan = []; // [id_geo => [id_leads...], label=>, phase=>]
$phaseCount = ['P1'=>0,'P2'=>0,'P3'=>0,'P4'=>0,'P5'=>0,'MISS'=>0];
$phasLeads  = ['P1'=>0,'P2'=>0,'P3'=>0,'P4'=>0,'P5'=>0,'MISS'=>0];

foreach ($rows as $r) {
    $barrio   = trim((string)($r['barrio']          ?? ''));
    $zona     = trim((string)($r['zona']            ?? ''));
    $mpio     = trim((string)($r['municipio']       ?? ''));
    $comuna   = trim((string)($r['comuna_localidad']?? ''));
    $n        = (int)$r['n'];

    // Bogotá localidades
    if ($mpio !== '' && stripos($mpio, 'LOCALIDAD') !== false) {
        $mpio = 'Bogota';
    }

    // Aplicar alias de municipio
    $mpioNorm = phpNorm($mpio);
    if (isset(MPIO_ALIASES[$mpioNorm])) {
        $mpioNorm = MPIO_ALIASES[$mpioNorm];
    }

    $geoId = null; $phase = 'MISS';

    // P1 – Barrio
    if ($geoId === null && $barrio !== '' && $zona !== '' && $mpio !== '') {
        $key = phpNorm($barrio) . '||' . phpNorm($zona) . '||' . $mpioNorm;
        if (isset($barrioMap[$key])) { $geoId = $barrioMap[$key]; $phase = 'P1'; }
    }

    // P2 – Comuna
    if ($geoId === null && $comuna !== '' && $mpio !== '') {
        $key = phpNorm($comuna) . '||' . $mpioNorm;
        if (isset($comunaMap[$key])) { $geoId = $comunaMap[$key]; $phase = 'P2'; }
    }

    // P3 – Municipio
    if ($geoId === null && $mpioNorm !== '') {
        if (isset($mpioMap[$mpioNorm])) { $geoId = $mpioMap[$mpioNorm]; $phase = 'P3'; }
    }

    $phaseCount[$phase]++;
    $phasLeads[$phase] += $n;

    if ($geoId !== null) {
        $plan[$geoId][] = $r['ids']; // store comma-separated lead ids
    }
}

/* ── Aplicar ─────────────────────────────── */
$applied = 0;
if ($apply && !empty($plan)) {
    // Preparar: UPDATE por id_geo en batch (GROUP_CONCAT ids)
    $stmtUpd = $pdo->prepare("UPDATE import_log_leads SET id_geo=? WHERE id_lead IN (?)");
    foreach ($plan as $geoId => $idGroups) {
        $allIds = implode(',', $idGroups); // "1,2,3,4..."
        $cnt = $pdo->exec("UPDATE import_log_leads SET id_geo=$geoId WHERE id_lead IN ($allIds)");
        $applied += $cnt;
    }
}

/* ── Estado final ────────────────────────── */
$postMatched = cl($pdo, 'id_geo IS NOT NULL');
$postNull    = $total - $postMatched;
$pct         = $total > 0 ? round($postMatched / $total * 100, 2) : 0;

// Top sin match
$stillNull = $pdo->query("
    SELECT TRIM(municipio) mpio, COUNT(*) n
    FROM import_log_leads
    WHERE id_geo IS NULL AND municipio IS NOT NULL AND municipio != ''
    GROUP BY mpio ORDER BY n DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$noMpio = cl($pdo, "id_geo IS NULL AND (municipio IS NULL OR municipio = '')");

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><title>Migración id_geo</title>
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
.ok{color:#4ade80}.warn{color:#facc15}.err{color:#f87171}.info{color:#60a5fa}
.btn{display:inline-block;padding:9px 22px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;margin:3px}
.btn-apply{background:#0ea5e9;color:#fff}
.btn-dry{background:#334155;color:#cbd5e1}
.btn-reset{background:#ef4444;color:#fff}
.prog{height:10px;border-radius:5px;background:#0f172a;overflow:hidden;margin-top:6px}
.prog-bar{height:100%;border-radius:5px}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
</style>
</head>
<body>
<h1>🌍 Migración import_log_leads.id_geo <small style="font-size:12px;color:#475569">(v2 PHP-loop)</small></h1>

<div class="card">
  <?= $apply ? '<span class="ok">✅ UPDATEs aplicados.</span>' : '<span class="warn">⚠ Modo DRY-RUN — sin cambios.</span>' ?>
  &nbsp;
  <a class="btn btn-apply" href="?apply=1">▶ Aplicar</a>
  <a class="btn btn-dry" href="?">🔍 Dry-run</a>
  <a class="btn btn-reset" href="?reset=1" onclick="return confirm('¿Resetear id_geo en todos?')">⚠ Reset</a>
</div>

<h2>Estado <?= $apply ? 'final' : 'actual' ?></h2>
<div class="row">
  <div class="kpi"><div class="kpi-val"><?= number_format($total) ?></div><div class="kpi-lbl">Total leads</div></div>
  <div class="kpi"><div class="kpi-val ok"><?= number_format($postMatched) ?></div><div class="kpi-lbl">Con id_geo</div></div>
  <div class="kpi"><div class="kpi-val <?= $postNull>0?'warn':'ok' ?>"><?= number_format($postNull) ?></div><div class="kpi-lbl">Sin id_geo</div></div>
  <div class="kpi"><div class="kpi-val info"><?= number_format($noMpio) ?></div><div class="kpi-lbl">Sin municipio</div></div>
  <div class="kpi"><div class="kpi-val"><?= $pct ?>%</div><div class="kpi-lbl">Cobertura</div></div>
</div>
<div class="card">
  <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px">
    <span>Cobertura id_geo</span><span><?= $pct ?>%</span>
  </div>
  <div class="prog"><div class="prog-bar" style="width:<?= $pct ?>%;background:#0ea5e9"></div></div>
</div>

<?php if ($apply): ?>
<h2>Resultado</h2>
<div class="card"><span class="ok">✅ <?= number_format($applied) ?> leads actualizados.</span></div>
<?php endif; ?>

<h2><?= $apply ? 'Aplicado' : 'Plan' ?> por fase</h2>
<table>
<tr><th>Fase</th><th>Combinaciones únicas</th><th>Leads</th></tr>
<tr><td><span class="badge" style="background:#86efac22;color:#86efac">P1 — Barrio (nivel=7)</span></td><td><?= number_format($phaseCount['P1']) ?></td><td class="ok"><?= number_format($phasLeads['P1']) ?></td></tr>
<tr><td><span class="badge" style="background:#facc1522;color:#facc15">P2 — Comuna (nivel=6)</span></td><td><?= number_format($phaseCount['P2']) ?></td><td class="ok"><?= number_format($phasLeads['P2']) ?></td></tr>
<tr><td><span class="badge" style="background:#fb923c22;color:#fb923c">P3 — Municipio (nivel=4)</span></td><td><?= number_format($phaseCount['P3']) ?></td><td class="ok"><?= number_format($phasLeads['P3']) ?></td></tr>
<tr><td><span class="badge" style="background:#f87171aa;color:#fff">Sin match</span></td><td><?= number_format($phaseCount['MISS']) ?></td><td class="err"><?= number_format($phasLeads['MISS']) ?></td></tr>
<tr style="border-top:2px solid #334155">
  <td><b>Total con match</b></td>
  <td><?= number_format($phaseCount['P1']+$phaseCount['P2']+$phaseCount['P3']) ?></td>
  <td class="ok"><b><?= number_format($phasLeads['P1']+$phasLeads['P2']+$phasLeads['P3']) ?></b></td>
</tr>
</table>

<?php if (!empty($stillNull)): ?>
<h2>Municipios sin match (top 20 — quedan sin id_geo)</h2>
<table>
<tr><th>#</th><th>municipio</th><th>leads</th></tr>
<?php foreach ($stillNull as $i => $r): ?>
<tr><td class="info"><?= $i+1 ?></td><td><?= htmlspecialchars($r['mpio']) ?></td><td class="warn"><?= number_format($r['n']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

</body></html>
