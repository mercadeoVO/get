<?php
/**
 * _geo2_diag.php
 * Diagnóstico: ¿cuántos leads pueden mapearse de dim_geo → c98_geo?
 *
 * Estrategia:
 *   Para cada id_geo distinto en import_log_leads:
 *     1. Obtener nombres desde dim_geo (nivel, nom_mpio, nom_barrio, nom_zona, nom_comuna)
 *     2. Buscar en c98_geo por municipio → zona → comuna → barrio
 *     3. Reportar cobertura y ejemplos de no-match
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
set_time_limit(120);

$pdo = getDB();

/* ── Normalizar nombre ────────────────────────── */
function norm(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
                       'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ñ'=>'n']);
}

/* ── Cargar c98_geo en PHP maps ───────────────── */
// mpio map: norm(nombre) → id (varchar 5)
$mpioMap = [];
foreach ($pdo->query("SELECT id, nombre, abreviatura FROM c98_geo.dim_municipio")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mpioMap[norm($r['nombre'])] = $r['id'];
    if ($r['abreviatura']) $mpioMap[norm($r['abreviatura'])] = $r['id'];
}

// zona map: norm(nombre)||id_mpio → id (varchar 8)
$zonaMap = [];
foreach ($pdo->query("SELECT id, nombre, id_municipio FROM c98_geo.dim_zona")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $zonaMap[norm($r['nombre']).'||'.$r['id_municipio']] = $r['id'];
}

// comuna map: norm(nombre)||id_mpio (via zona) → id (varchar 11)
$comunaMap = [];
foreach ($pdo->query("
    SELECT c.id, c.nombre, c.id_zona, z.id_municipio
    FROM c98_geo.dim_comuna c
    JOIN c98_geo.dim_zona z ON z.id = c.id_zona
")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $comunaMap[norm($r['nombre']).'||'.$r['id_municipio']] = $r['id'];
}

// barrio map: norm(nombre)||id_mpio (via comuna→zona) → id (varchar 15)
$barrioMap = [];
foreach ($pdo->query("
    SELECT b.id, b.nombre, z.id_municipio
    FROM c98_geo.dim_barrio b
    JOIN c98_geo.dim_comuna c ON c.id = b.id_comuna
    JOIN c98_geo.dim_zona   z ON z.id = c.id_zona
")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $barrioMap[norm($r['nombre']).'||'.$r['id_municipio']] = $r['id'];
}

/* ── Obtener cada id_geo distinto con sus nombres ── */
$dimGeoRows = $pdo->query("
    SELECT g.id, g.nivel,
           g.nom_mpio, g.nom_barrio, g.nom_zona, g.nom_comuna, g.nom_dpto,
           COUNT(l.id_lead) AS n_leads
    FROM medfut.dim_geo g
    JOIN medfut.import_log_leads l ON l.id_geo = g.id
    GROUP BY g.id
    ORDER BY n_leads DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Intentar mapear cada uno ─────────────────── */
$results = [];
$stats = ['barrio'=>0,'comuna'=>0,'mpio'=>0,'miss'=>0,'leads_ok'=>0,'leads_miss'=>0];

foreach ($dimGeoRows as $g) {
    $mpioId  = null;
    $zonaId  = null;
    $comunaId= null;
    $barrioId= null;
    $phase   = 'MISS';

    $normMpio   = norm($g['nom_mpio']   ?? '');
    $normBarrio = norm($g['nom_barrio'] ?? '');
    $normZona   = norm($g['nom_zona']   ?? '');
    $normComuna = norm($g['nom_comuna'] ?? '');

    // Paso 1: municipio
    if ($normMpio && isset($mpioMap[$normMpio])) {
        $mpioId = $mpioMap[$normMpio];
    }

    if ($mpioId) {
        // Paso 2: barrio (P1)
        if ($normBarrio) {
            $key = $normBarrio.'||'.$mpioId;
            if (isset($barrioMap[$key])) { $barrioId = $barrioMap[$key]; $phase = 'barrio'; }
        }
        // Paso 3: comuna (P2)
        if (!$barrioId && $normComuna) {
            $key = $normComuna.'||'.$mpioId;
            if (isset($comunaMap[$key])) { $comunaId = $comunaMap[$key]; $phase = 'comuna'; }
        }
        // Paso 4: zona (P2b)
        if (!$barrioId && !$comunaId && $normZona) {
            $key = $normZona.'||'.$mpioId;
            if (isset($zonaMap[$key])) { $zonaId = $zonaMap[$key]; $phase = 'zona'; }
        }
        // Paso 5: municipio fallback (P3)
        if (!$barrioId && !$comunaId && !$zonaId) {
            $phase = 'mpio';
        }
    }

    $newId = $barrioId ?? $comunaId ?? $zonaId ?? $mpioId ?? null;
    if ($phase !== 'MISS') $stats[$phase === 'zona' ? 'comuna' : $phase]++;
    else $stats['miss']++;

    if ($newId) $stats['leads_ok']  += (int)$g['n_leads'];
    else        $stats['leads_miss'] += (int)$g['n_leads'];

    $results[] = [
        'old_id'   => $g['id'],
        'nivel'    => $g['nivel'],
        'nom_mpio' => $g['nom_mpio'],
        'nom_barrio'=> $g['nom_barrio'],
        'nom_comuna'=> $g['nom_comuna'],
        'n_leads'  => (int)$g['n_leads'],
        'phase'    => $phase,
        'new_id'   => $newId,
    ];
}

$totalLeads = $stats['leads_ok'] + $stats['leads_miss'];
$pct = $totalLeads > 0 ? round($stats['leads_ok']/$totalLeads*100,1) : 0;

/* ── Miss samples ─────────────────────────────── */
$misses = array_filter($results, fn($r) => $r['phase'] === 'MISS');
usort($misses, fn($a,$b) => $b['n_leads'] - $a['n_leads']);
$misses = array_slice(array_values($misses), 0, 20);

?><!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Diagnóstico geo2</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:monospace;font-size:13px;background:#0f172a;color:#e2e8f0;padding:24px}
h1{color:#38bdf8;font-size:17px;margin-bottom:16px}
h2{color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin:20px 0 8px}
.row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.kpi{background:#1e293b;border-radius:8px;padding:12px 20px;text-align:center;flex:1;min-width:120px}
.kpi-val{font-size:26px;font-weight:700}
.kpi-lbl{font-size:10px;color:#64748b;text-transform:uppercase;margin-top:2px}
.card{background:#1e293b;border-radius:8px;padding:14px 18px;margin-bottom:12px}
table{border-collapse:collapse;width:100%;font-size:12px}
th{background:#0f172a;color:#64748b;padding:6px 10px;text-align:left;border-bottom:1px solid #334155;font-size:10px;text-transform:uppercase}
td{padding:5px 10px;border-bottom:1px solid #1e293b}
.ok{color:#4ade80}.warn{color:#facc15}.err{color:#f87171}.info{color:#60a5fa}
.prog{height:10px;border-radius:5px;background:#0f172a;overflow:hidden;margin-top:6px}
.prog-bar{height:100%;border-radius:5px}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
</style>
</head>
<body>
<h1>🔬 Diagnóstico mapeo dim_geo → c98_geo</h1>

<h2>Cobertura de leads (<?= number_format($totalLeads) ?> con id_geo)</h2>
<div class="row">
  <div class="kpi"><div class="kpi-val ok"><?= $stats['barrio'] ?></div><div class="kpi-lbl">→ Barrio</div></div>
  <div class="kpi"><div class="kpi-val ok"><?= $stats['comuna'] ?></div><div class="kpi-lbl">→ Comuna/Zona</div></div>
  <div class="kpi"><div class="kpi-val ok"><?= $stats['mpio'] ?></div><div class="kpi-lbl">→ Municipio</div></div>
  <div class="kpi"><div class="kpi-val err"><?= $stats['miss'] ?></div><div class="kpi-lbl">Sin match</div></div>
  <div class="kpi"><div class="kpi-val info"><?= $pct ?>%</div><div class="kpi-lbl">Leads OK</div></div>
</div>
<div class="card">
  <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px">
    <span>Leads con nuevo id_geo</span><span class="<?= $pct>=90?'ok':($pct>=70?'warn':'err') ?>"><?= $pct ?>%</span>
  </div>
  <div class="prog"><div class="prog-bar" style="width:<?= $pct ?>%;background:<?= $pct>=90?'#22c55e':($pct>=70?'#f59e0b':'#ef4444') ?>"></div></div>
</div>

<h2>Distribución de matches (por id_geo distinto)</h2>
<div class="card">
  <table>
    <tr><th>Fase</th><th>id_geo distintos</th><th>Leads</th></tr>
    <?php
    $phaseLabels = [
      'barrio' => ['label'=>'P1 — Barrio','color'=>'#86efac'],
      'comuna' => ['label'=>'P2 — Comuna/Zona','color'=>'#facc15'],
      'mpio'   => ['label'=>'P3 — Municipio','color'=>'#fb923c'],
      'miss'   => ['label'=>'Sin match','color'=>'#f87171'],
    ];
    foreach ($phaseLabels as $phase => $info):
        $cnt   = $stats[$phase];
        $leads = array_sum(array_column(array_filter($results, fn($r)=>$r['phase']===$phase||($phase==='mpio'&&$r['phase']==='mpio')), 'n_leads'));
    ?>
    <tr>
      <td><span class="badge" style="background:<?= $info['color'] ?>22;color:<?= $info['color'] ?>"><?= $info['label'] ?></span></td>
      <td><?= number_format($cnt) ?></td>
      <td><?= number_format($leads) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if (!empty($misses)): ?>
<h2>Sin match — top <?= count($misses) ?> (por leads afectados)</h2>
<table>
  <tr><th>#</th><th>nom_mpio</th><th>nom_barrio</th><th>nom_comuna</th><th>leads</th><th>motivo</th></tr>
  <?php foreach ($misses as $i => $r): ?>
  <tr>
    <td class="info"><?= $i+1 ?></td>
    <td><?= htmlspecialchars($r['nom_mpio'] ?? '—') ?></td>
    <td><?= htmlspecialchars($r['nom_barrio'] ?? '—') ?></td>
    <td><?= htmlspecialchars($r['nom_comuna'] ?? '—') ?></td>
    <td class="warn"><?= number_format($r['n_leads']) ?></td>
    <td class="err">mpio "<?= htmlspecialchars(norm($r['nom_mpio']??'')) ?>" no hallado en c98_geo</td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

</body></html>
