<?php
/**
 * _geo_audit_medellin.php — Auditoría barrios vs comunas Medellín
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = getDB();

// ── 1. Comunas autorizadas (nivel=6) ──────────────────────────
$d6 = $pdo->query("
    SELECT DISTINCT nom_zona, nom_comuna
    FROM dim_geo
    WHERE nivel=6 AND nom_mpio='Medellín'
    ORDER BY nom_zona, nom_comuna
")->fetchAll(PDO::FETCH_ASSOC);

$authZone = []; // [COMUNA] => ZONA
foreach ($d6 as $r) {
    $c = mb_strtoupper(trim($r['nom_comuna']), 'UTF-8');
    $z = mb_strtoupper(trim($r['nom_zona']), 'UTF-8');
    $authZone[$c] = $z;
}

// ── 2. Barrios (nivel=7) ─────────────────────────────────────
$d7 = $pdo->query("
    SELECT nom_zona, nom_comuna, nom_barrio
    FROM dim_geo
    WHERE nivel=7 AND nom_mpio='Medellín'
    ORDER BY nom_zona, nom_comuna, nom_barrio
")->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Cruzar: detectar zona inconsistente ────────────────────
$errors = [];
$byComune = [];
foreach ($d7 as $r) {
    $c = mb_strtoupper(trim($r['nom_comuna']), 'UTF-8');
    $z = mb_strtoupper(trim($r['nom_zona']), 'UTF-8');
    $b = $r['nom_barrio'];
    $byComune[$c][$z][] = $b;

    $expectedZ = $authZone[$c] ?? null;
    if ($expectedZ !== null && $expectedZ !== $z) {
        $errors[] = ['barrio'=>$b,'comuna'=>$c,'zona_d7'=>$z,'zona_d6'=>$expectedZ];
    }
    if ($expectedZ === null) {
        $errors[] = ['barrio'=>$b,'comuna'=>$c,'zona_d7'=>$z,'zona_d6'=>'⚠ COMUNA NO EN d6'];
    }
}

// ── 4. Comunas en d6 sin barrios en d7 ───────────────────────
$d6comunas = array_keys($authZone);
$d7comunas = array_unique(array_map(fn($r)=>mb_strtoupper(trim($r['nom_comuna']),'UTF-8'), $d7));
$sinBarrios = array_diff($d6comunas, $d7comunas);

?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Auditoría Medellín</title>
<style>
body{font-family:monospace;font-size:12px;background:#0f172a;color:#e2e8f0;padding:20px}
h2{color:#38bdf8;margin:20px 0 8px}
table{border-collapse:collapse;width:100%;margin-bottom:20px}
th{background:#1e293b;color:#94a3b8;padding:6px 10px;text-align:left;border:1px solid #334155;font-size:10px;text-transform:uppercase}
td{padding:5px 10px;border:1px solid #1e293b;font-size:11px}
tr:nth-child(even) td{background:#0d1929}
.err{color:#f87171}.ok{color:#4ade80}.warn{color:#facc15}
.badge{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px}
.b-z{background:rgba(244,114,182,.1);color:#f472b6}
.b-c{background:rgba(250,204,21,.1);color:#facc15}
.b-b{background:rgba(134,239,172,.1);color:#86efac}
</style></head><body>

<h2>🔍 Auditoría Geo Medellín</h2>

<!-- Resumen d6 -->
<h2>1. Comunas registradas (nivel=6)</h2>
<table>
<tr><th>Zona</th><th>Comunas en zona</th></tr>
<?php
$byZone6 = [];
foreach ($d6 as $r) $byZone6[$r['nom_zona']][] = $r['nom_comuna'];
foreach ($byZone6 as $z => $coms):
?>
<tr>
  <td><span class="badge b-z"><?= htmlspecialchars($z) ?></span></td>
  <td><?= implode(' · ', array_map(fn($c)=>'<span class="badge b-c">'.htmlspecialchars($c).'</span>', $coms)) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- Conteo d7 por comuna -->
<h2>2. Barrios por zona/comuna (nivel=7)</h2>
<table>
<tr><th>Zona (d7)</th><th>Comuna (d7)</th><th>N Barrios</th><th>Zona esperada (d6)</th><th>Estado</th></tr>
<?php foreach ($byComune as $com => $zones): ?>
  <?php foreach ($zones as $z => $bars):
    $exp = $authZone[$com] ?? '—';
    $ok  = ($exp === $z);
    $cls = $ok ? 'ok' : 'err';
  ?>
  <tr>
    <td><span class="badge b-z"><?= htmlspecialchars($z) ?></span></td>
    <td><span class="badge b-c"><?= htmlspecialchars($com) ?></span></td>
    <td style="text-align:center"><span class="badge b-b"><?= count($bars) ?></span></td>
    <td><span class="badge b-z"><?= htmlspecialchars($exp) ?></span></td>
    <td class="<?= $cls ?>"><?= $ok ? '✓ OK' : '✗ MISMATCH' ?></td>
  </tr>
  <?php endforeach; ?>
<?php endforeach; ?>
</table>

<!-- Errores zona ─────────────── -->
<h2>3. Barrios con zona incorrecta en d7</h2>
<?php if (empty($errors)): ?>
  <p class="ok">✓ Sin discrepancias de zona</p>
<?php else: ?>
  <table>
  <tr><th>Barrio</th><th>Comuna</th><th>Zona en d7</th><th>Zona esperada (d6)</th></tr>
  <?php foreach ($errors as $e): ?>
  <tr>
    <td><span class="badge b-b"><?= htmlspecialchars($e['barrio']) ?></span></td>
    <td><span class="badge b-c"><?= htmlspecialchars($e['comuna']) ?></span></td>
    <td class="err"><?= htmlspecialchars($e['zona_d7']) ?></td>
    <td class="warn"><?= htmlspecialchars($e['zona_d6']) ?></td>
  </tr>
  <?php endforeach; ?>
  </table>
<?php endif; ?>

<!-- Comunas sin barrios ─────── -->
<h2>4. Comunas en d6 sin barrios en d7</h2>
<?php if (empty($sinBarrios)): ?>
  <p class="ok">✓ Todas las comunas tienen barrios</p>
<?php else: ?>
  <table>
  <tr><th>Comuna</th><th>Zona (d6)</th></tr>
  <?php foreach ($sinBarrios as $c): ?>
  <tr>
    <td><span class="badge b-c"><?= htmlspecialchars($c) ?></span></td>
    <td><span class="badge b-z"><?= htmlspecialchars($authZone[$c]) ?></span></td>
  </tr>
  <?php endforeach; ?>
  </table>
<?php endif; ?>

<!-- Lista completa barrios ───── -->
<h2>5. Lista completa d7 por zona/comuna</h2>
<?php foreach ($byComune as $com => $zones): ?>
  <?php foreach ($zones as $z => $bars): sort($bars); ?>
  <details>
    <summary style="cursor:pointer;margin:4px 0">
      <span class="badge b-z"><?= htmlspecialchars($z) ?></span>
      <span class="badge b-c"><?= htmlspecialchars($com) ?></span>
      <span class="badge b-b"><?= count($bars) ?> bar.</span>
    </summary>
    <div style="padding:4px 20px;color:#64748b">
      <?= implode(' · ', array_map('htmlspecialchars', $bars)) ?>
    </div>
  </details>
  <?php endforeach; ?>
<?php endforeach; ?>

</body></html>
