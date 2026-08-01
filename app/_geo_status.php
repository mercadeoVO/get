<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = getDB();
$r = $pdo->query("SELECT COUNT(*) total, SUM(id_geo IS NOT NULL) matched, SUM(id_geo IS NULL) nulls FROM import_log_leads")->fetch(PDO::FETCH_ASSOC);
$pct = $r['total'] > 0 ? round($r['matched']/$r['total']*100, 2) : 0;
echo "Total: {$r['total']} | Con id_geo: {$r['matched']} | Sin id_geo: {$r['nulls']} | Cobertura: $pct%\n";
