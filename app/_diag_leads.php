<?php
/**
 * GET · Diagnóstico de import_log_leads
 * http://localhost/get/_diag_leads.php
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$out = [];

// 1. Tamaño de tablas
$out['tamaño_tablas'] = $db->query("
    SELECT table_name,
           ROUND(data_length/1024/1024,2)  AS data_MB,
           ROUND(index_length/1024/1024,2) AS index_MB,
           ROUND((data_length+index_length)/1024/1024,2) AS total_MB,
           table_rows AS filas_aprox
    FROM information_schema.tables
    WHERE table_schema=DATABASE()
      AND table_name IN ('import_log_leads','import_log_leads_respuestas','import_log','import_electoral')
    ORDER BY table_name
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Nulos por columna en import_log_leads
$total = (int)$db->query("SELECT COUNT(*) FROM import_log_leads")->fetchColumn();
$out['total_leads'] = $total;

$cols = ['ad_id','ad_name','adset_id','adset_name','campaign_id','campaign_name',
         'form_id','form_name','nombre','email','telefono','sexo','rango_edad',
         'estrato','afiliacion_politica','nivel_educativo','geo_departamento',
         'subregion','municipio','zona','comuna_localidad','barrio','id_geo','platform'];

$nullStats = [];
foreach ($cols as $col) {
    $nulls = (int)$db->query("SELECT COUNT(*) FROM import_log_leads WHERE `$col` IS NULL")->fetchColumn();
    $nullStats[$col] = [
        'nulos'     => $nulls,
        'con_dato'  => $total - $nulls,
        'pct_nulo'  => $total > 0 ? round($nulls/$total*100,1) : 0,
    ];
}
$out['nulos_por_columna'] = $nullStats;

// 3. Cardinalidad de columnas categóricas (para detectar normalización posible)
$catCols = ['sexo','rango_edad','estrato','afiliacion_politica','nivel_educativo','platform',
            'geo_departamento','subregion','municipio','zona','comuna_localidad'];
$cardinalidad = [];
foreach ($catCols as $col) {
    $cnt = (int)$db->query("SELECT COUNT(DISTINCT `$col`) FROM import_log_leads")->fetchColumn();
    $cardinalidad[$col] = $cnt;
}
$out['cardinalidad'] = $cardinalidad;

// 4. Valores únicos de columnas con baja cardinalidad
$distrib = [];
foreach (['sexo','rango_edad','platform','estrato'] as $col) {
    $rows = $db->query("
        SELECT `$col` AS val, COUNT(*) AS cnt
        FROM import_log_leads
        GROUP BY `$col`
        ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $distrib[$col] = $rows;
}
$out['distribuciones_categoricas'] = $distrib;

// 5. Longitudes máximas reales vs definidas (para reducir VARCHAR)
$lenCols = ['id','ad_id','adset_id','campaign_id','form_id','nombre','email',
            'telefono','ad_name','campaign_name','adset_name','form_name'];
$longitudes = [];
foreach ($lenCols as $col) {
    $maxLen = $db->query("SELECT MAX(LENGTH(`$col`)) FROM import_log_leads")->fetchColumn();
    $longitudes[$col] = (int)$maxLen;
}
$out['longitud_maxima_real'] = $longitudes;

// 6. Inconsistencias de formato en campos clave
$out['inconsistencias'] = [];

// rango_edad: mezcla de formatos (18a24 vs 18-24)
$edadFormatos = $db->query("
    SELECT rango_edad, COUNT(*) AS cnt FROM import_log_leads
    WHERE rango_edad IS NOT NULL GROUP BY rango_edad ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);
$out['inconsistencias']['rango_edad_valores'] = $edadFormatos;

// sexo: variantes de capitalización
$sexoVals = $db->query("
    SELECT sexo, COUNT(*) AS cnt FROM import_log_leads
    WHERE sexo IS NOT NULL GROUP BY sexo ORDER BY cnt DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
$out['inconsistencias']['sexo_valores'] = $sexoVals;

// afiliacion_politica: valores con guiones vs sin
$afilVals = $db->query("
    SELECT afiliacion_politica, COUNT(*) AS cnt FROM import_log_leads
    WHERE afiliacion_politica IS NOT NULL GROUP BY afiliacion_politica ORDER BY cnt DESC LIMIT 25
")->fetchAll(PDO::FETCH_ASSOC);
$out['inconsistencias']['afiliacion_politica'] = $afilVals;

// 7. Índices actuales
$out['indices'] = $db->query("SHOW INDEX FROM import_log_leads")->fetchAll(PDO::FETCH_ASSOC);

// 8. Columnas con datos vacíos '' (no null, pero vacíos)
$emptyStats = [];
foreach (['nombre','email','telefono','sexo','rango_edad','afiliacion_politica','nivel_educativo'] as $col) {
    $cnt = (int)$db->query("SELECT COUNT(*) FROM import_log_leads WHERE `$col` = ''")->fetchColumn();
    if ($cnt > 0) $emptyStats[$col] = $cnt;
}
$out['vacios_string'] = $emptyStats;

// 9. Duplicados de email (misma persona, distinto lead_id)
$dupEmail = (int)$db->query("
    SELECT COUNT(*) FROM (
        SELECT email FROM import_log_leads
        WHERE email IS NOT NULL AND email != ''
        GROUP BY email HAVING COUNT(*) > 1
    ) t
")->fetchColumn();
$out['emails_duplicados'] = $dupEmail;

// 10. Respuestas EAV: preguntas más frecuentes
$out['respuestas_preguntas_top'] = $db->query("
    SELECT pregunta, COUNT(*) AS cnt
    FROM import_log_leads_respuestas
    GROUP BY pregunta ORDER BY cnt DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
