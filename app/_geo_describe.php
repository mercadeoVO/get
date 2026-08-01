<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = getDB();
$cols = $pdo->query("DESCRIBE dim_geo")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo $c['Field'].' | '.$c['Type'].' | '.$c['Key'].PHP_EOL;
