<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = getDB();

// Municipios en dim_geo (nivel=4) → nombre canónico
$geo = $pdo->query("
    SELECT nom_mpio, nom_dpto, COUNT(*) n
    FROM dim_geo WHERE nivel=4
    GROUP BY nom_mpio, nom_dpto
    ORDER BY nom_mpio
")->fetchAll(PDO::FETCH_ASSOC);
$geoMap = []; // upper_trim → nom_mpio canónico
foreach ($geo as $r) $geoMap[mb_strtoupper(trim($r['nom_mpio']),'UTF-8')] = $r['nom_mpio'];

// Municipios en import_log_leads (distintos, con count)
$leads = $pdo->query("
    SELECT UPPER(TRIM(municipio)) up, municipio, COUNT(*) n
    FROM import_log_leads
    WHERE municipio IS NOT NULL AND municipio != ''
    GROUP BY municipio
    ORDER BY n DESC
")->fetchAll(PDO::FETCH_ASSOC);

$matched = []; $unmatched = [];
foreach ($leads as $r) {
    $up = $r['up'];
    if (isset($geoMap[$up])) {
        $matched[] = ['lead_mpio'=>$r['municipio'],'geo_mpio'=>$geoMap[$up],'n'=>$r['n'],'exact'=>($r['municipio']===$geoMap[$up])];
    } else {
        $unmatched[] = ['lead_mpio'=>$r['municipio'],'n'=>$r['n']];
    }
}

$nMatched   = array_sum(array_column($matched,'n'));
$nUnmatched = array_sum(array_column($unmatched,'n'));

echo "=== MATCH SUMMARY ===\n";
echo "Municipios distintos en leads: ".count($leads)."\n";
echo "Con match en dim_geo: ".count($matched)." (". $nMatched." leads)\n";
echo "Sin match:            ".count($unmatched)." (". $nUnmatched." leads)\n\n";

echo "=== SIN MATCH (ordenado por volumen) ===\n";
usort($unmatched, fn($a,$b)=>$b['n']-$a['n']);
foreach ($unmatched as $u)
    echo $u['n']."\t".$u['lead_mpio']."\n";

echo "\n=== MATCH EXACTO VS CASE-ONLY ===\n";
$caseOnly = array_filter($matched, fn($m)=>!$m['exact']);
foreach ($caseOnly as $m)
    echo $m['n']."\t[lead] ".$m['lead_mpio']." → [geo] ".$m['geo_mpio']."\n";
