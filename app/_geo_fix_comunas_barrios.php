<?php
/**
 * _geo_fix_comunas_barrios.php
 * Corrige nom_comuna en nivel=7 de Medellín usando mapa Excel oficial.
 * Clave compuesta: BARRIO_UPPER||ZONA_UPPER → com_d6 (evita colisiones de nombres duplicados)
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = getDB();
$mapFile = __DIR__ . '/_barrio_zona_commune_map.json';
if (!file_exists($mapFile)) die('No se encontró _barrio_zona_commune_map.json');

// $excelMap["BARRIO||ZONA"] = "XX NOMBRE_COMUNA"
$excelMap = json_decode(file_get_contents($mapFile), true);

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// Leer todos los barrios de Medellín (nivel=7)
$rows = $pdo->query("
    SELECT id, nom_barrio, nom_zona, nom_comuna
    FROM dim_geo
    WHERE nivel=7 AND nom_mpio='Medellín'
    ORDER BY nom_zona, nom_comuna, nom_barrio
")->fetchAll(PDO::FETCH_ASSOC);

$updates  = [];
$noChange = [];
$noMatch  = [];

foreach ($rows as $r) {
    $bUp  = mb_strtoupper(trim($r['nom_barrio']), 'UTF-8');
    $zUp  = mb_strtoupper(trim($r['nom_zona']),   'UTF-8');
    $key  = $bUp . '||' . $zUp;
    $cOld = mb_strtoupper(trim($r['nom_comuna']), 'UTF-8');

    if (isset($excelMap[$key])) {
        $newCom = $excelMap[$key];
        if ($newCom !== $cOld) {
            $updates[] = [
                'id'      => $r['id'],
                'barrio'  => $r['nom_barrio'],
                'zona'    => $r['nom_zona'],
                'old_com' => $r['nom_comuna'],
                'new_com' => $newCom,
            ];
        } else {
            $noChange[] = $r['nom_barrio'];
        }
    } else {
        $noMatch[] = ['id'=>$r['id'],'barrio'=>$r['nom_barrio'],'zona'=>$r['nom_zona'],'com'=>$r['nom_comuna']];
    }
}

// Aplicar
$applied = 0;
if ($apply && !empty($updates)) {
    $stmt = $pdo->prepare("UPDATE dim_geo SET nom_comuna=? WHERE id=? AND nivel=7 AND nom_mpio='Medellín'");
    foreach ($updates as $u) {
        $stmt->execute([$u['new_com'], $u['id']]);
        $applied += $stmt->rowCount();
    }
}
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Fix Comunas Barrios</title>
<style>
body{font-family:monospace;font-size:12px;background:#0f172a;color:#e2e8f0;padding:20px}
h2{color:#38bdf8;margin:20px 0 8px}
table{border-collapse:collapse;width:100%;margin-bottom:16px}
th{background:#1e293b;color:#94a3b8;padding:6px 10px;text-align:left;border:1px solid #334155;font-size:10px;text-transform:uppercase}
td{padding:5px 10px;border:1px solid #1e293b;font-size:11px}
tr:nth-child(even) td{background:#0d1929}
.err{color:#f87171}.ok{color:#4ade80}.warn{color:#facc15}.dim{color:#475569}
.badge{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px}
.b-z{background:rgba(244,114,182,.1);color:#f472b6}
.b-c{background:rgba(250,204,21,.1);color:#facc15}
.b-b{background:rgba(134,239,172,.1);color:#86efac}
.btn{display:inline-block;margin:4px;padding:8px 20px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;cursor:pointer}
.btn-go{background:#0ea5e9;color:#fff}
</style></head><body>

<h2>🔧 Fix Comunas/Barrios Medellín (clave barrio+zona)</h2>

<p>
  Total d7: <b><?= count($rows) ?></b> &nbsp;|&nbsp;
  Con cambio: <span class="<?= count($updates)?'warn':'ok' ?>"><?= count($updates) ?></span> &nbsp;|&nbsp;
  Sin cambio: <span class="ok"><?= count($noChange) ?></span> &nbsp;|&nbsp;
  Sin match: <span class="<?= count($noMatch)?'err':'ok' ?>"><?= count($noMatch) ?></span>
</p>

<?php if ($apply): ?>
<p class="ok">✅ Aplicados: <b><?= $applied ?></b> UPDATEs exitosamente.</p>
<?php elseif (!empty($updates)): ?>
<p class="warn">⚠ DRY RUN — revisa la tabla abajo y luego aplica:
  <a class="btn btn-go" href="?apply=1">✔ Aplicar <?= count($updates) ?> cambios</a>
</p>
<?php else: ?>
<p class="ok">✅ Todo correcto — no hay cambios pendientes.</p>
<?php endif; ?>

<?php if (!empty($updates)): ?>
<h2>Cambios pendientes / aplicados (<?= count($updates) ?>)</h2>
<table>
<tr><th>Barrio</th><th>Zona</th><th>Comuna actual</th><th>→ Comuna correcta</th></tr>
<?php foreach ($updates as $u): ?>
<tr>
  <td><span class="badge b-b"><?= htmlspecialchars($u['barrio']) ?></span></td>
  <td><span class="badge b-z"><?= htmlspecialchars($u['zona']) ?></span></td>
  <td class="err"><?= htmlspecialchars($u['old_com']) ?></td>
  <td><span class="badge b-c"><?= htmlspecialchars($u['new_com']) ?></span></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!empty($noMatch)): ?>
<h2 class="err">Sin match en Excel (<?= count($noMatch) ?>) — no modificados</h2>
<table>
<tr><th>ID</th><th>Barrio</th><th>Zona</th><th>Comuna actual</th></tr>
<?php foreach ($noMatch as $n): ?>
<tr>
  <td><?= $n['id'] ?></td>
  <td><?= htmlspecialchars($n['barrio']) ?></td>
  <td><?= htmlspecialchars($n['zona']) ?></td>
  <td><?= htmlspecialchars($n['com']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

</body></html>
