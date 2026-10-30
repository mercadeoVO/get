<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>GET · Enriquecimiento Geográfico</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0f1117;color:#e8eaf0;font-family:'Segoe UI',monospace,sans-serif;font-size:13px;padding:24px}
h2{font-size:16px;font-weight:700;margin-bottom:4px;color:#fff}
.sub{color:#7b84a3;font-size:11px;margin-bottom:20px}
.paso{background:#1e2132;border:1px solid #2d3148;border-radius:8px;padding:16px 20px;margin-bottom:14px}
.paso-title{font-size:12px;font-weight:700;color:#818cf8;margin-bottom:10px}
.line{padding:2px 0;color:#c8cae0;font-size:12px}
.line code{color:#22d3ee;font-family:monospace}
.ok{color:#4ade80}.warn{color:#f59e0b}.err{color:#f87171}.mut{color:#7b84a3}
.kpi{display:inline-block;background:#6366f120;border:1px solid #6366f140;border-radius:6px;
     padding:3px 10px;font-weight:700;color:#818cf8;margin:2px 4px;font-size:12px}
.run-btn{
  background:#6366f1;color:#fff;border:none;padding:10px 24px;border-radius:8px;
  font-size:13px;font-weight:700;cursor:pointer;margin-bottom:20px;
}
.run-btn:hover{background:#4f46e5}
</style>
</head>
<body>
<h2>🔧 GET · Enriquecimiento Geográfico</h2>
<div class="sub">Corrige <code>geo_departamento</code> desde subregión · Mueve municipios mal clasificados en subregión · Enriquece desde c98_geo</div>

<?php
// ── Config ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

// Normaliza string para comparación: mayúsculas sin tildes
function normStr(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return str_replace(
        ['Á','À','Â','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Ö','Ú','Ù','Û','Ü','Ñ','Ç'],
        ['A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','U','U','U','U','N','C'],
        $s
    );
}

// ── Alias de municipios conocidos ────────────────────────────────────────────
// Clave = normStr(variación) → datos canónicos.
// Resuelve: nombres sin tilde, nombres comunes vs. oficiales, typos frecuentes.
const MPIO_ALIASES = [
    // Medellín y variaciones
    'MEDELLIN'                   => ['mpio'=>'Medellín',           'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    // Cali (oficial: Santiago de Cali)
    'CALI'                       => ['mpio'=>'Santiago de Cali',   'dpto'=>'Valle del Cauca',     'sub'=>null],
    'SANTIAGO DE CALI'           => ['mpio'=>'Santiago de Cali',   'dpto'=>'Valle del Cauca',     'sub'=>null],
    // Cúcuta (oficial: San José de Cúcuta)
    'CUCUTA'                     => ['mpio'=>'Cúcuta',             'dpto'=>'Norte de Santander',  'sub'=>null],
    'SAN JOSE DE CUCUTA'         => ['mpio'=>'Cúcuta',             'dpto'=>'Norte de Santander',  'sub'=>null],
    // Bogotá
    'BOGOTA'                     => ['mpio'=>'Bogotá D.C.',        'dpto'=>'Bogotá D.C.',         'sub'=>'Bogotá D.C.'],
    'BOGOTA DC'                  => ['mpio'=>'Bogotá D.C.',        'dpto'=>'Bogotá D.C.',         'sub'=>'Bogotá D.C.'],
    'BOGOTA D.C.'                => ['mpio'=>'Bogotá D.C.',        'dpto'=>'Bogotá D.C.',         'sub'=>'Bogotá D.C.'],
    // Barranquilla
    'BARRANQUILLA'               => ['mpio'=>'Barranquilla',       'dpto'=>'Atlántico',           'sub'=>null],
    // Cartagena
    'CARTAGENA'                  => ['mpio'=>'Cartagena de Indias','dpto'=>'Bolívar',             'sub'=>null],
    'CARTAGENA DE INDIAS'        => ['mpio'=>'Cartagena de Indias','dpto'=>'Bolívar',             'sub'=>null],
    // Ibagué
    'IBAGUE'                     => ['mpio'=>'Ibagué',             'dpto'=>'Tolima',              'sub'=>null],
    // Manizales + typo frecuente
    'MANIZALES'                  => ['mpio'=>'Manizales',          'dpto'=>'Caldas',              'sub'=>null],
    'MANIZALEZ'                  => ['mpio'=>'Manizales',          'dpto'=>'Caldas',              'sub'=>null],
    // Pereira
    'PEREIRA'                    => ['mpio'=>'Pereira',            'dpto'=>'Risaralda',           'sub'=>null],
    // Bucaramanga
    'BUCARAMANGA'                => ['mpio'=>'Bucaramanga',        'dpto'=>'Santander',           'sub'=>null],
    // Popayán + sin tilde
    'POPAYAN'                    => ['mpio'=>'Popayán',            'dpto'=>'Cauca',               'sub'=>null],
    // Neiva
    'NEIVA'                      => ['mpio'=>'Neiva',              'dpto'=>'Huila',               'sub'=>null],
    // Riohacha + typo frecuente
    'RIOHACHA'                   => ['mpio'=>'Riohacha',           'dpto'=>'La Guajira',          'sub'=>null],
    'RIOACHA'                    => ['mpio'=>'Riohacha',           'dpto'=>'La Guajira',          'sub'=>null],
    // Santa Marta
    'SANTA MARTA'                => ['mpio'=>'Santa Marta',        'dpto'=>'Magdalena',           'sub'=>null],
    // Valledupar
    'VALLEDUPAR'                 => ['mpio'=>'Valledupar',         'dpto'=>'Cesar',               'sub'=>null],
    // Montería + sin tilde
    'MONTERIA'                   => ['mpio'=>'Montería',           'dpto'=>'Córdoba',             'sub'=>null],
    // Sincelejo
    'SINCELEJO'                  => ['mpio'=>'Sincelejo',          'dpto'=>'Sucre',               'sub'=>null],
    // Villavicencio
    'VILLAVICENCIO'              => ['mpio'=>'Villavicencio',      'dpto'=>'Meta',                'sub'=>null],
    // Armenia
    'ARMENIA'                    => ['mpio'=>'Armenia',            'dpto'=>'Quindío',             'sub'=>null],
    // Pasto
    'PASTO'                      => ['mpio'=>'Pasto',              'dpto'=>'Nariño',              'sub'=>null],
    // Tunja
    'TUNJA'                      => ['mpio'=>'Tunja',              'dpto'=>'Boyacá',              'sub'=>null],
    // Florencia
    'FLORENCIA'                  => ['mpio'=>'Florencia',          'dpto'=>'Caquetá',             'sub'=>null],
    // Yopal
    'YOPAL'                      => ['mpio'=>'Yopal',              'dpto'=>'Casanare',            'sub'=>null],
    // Arauca
    'ARAUCA'                     => ['mpio'=>'Arauca',             'dpto'=>'Arauca',              'sub'=>null],
    // San Andrés + variaciones
    'SAN ANDRES'                 => ['mpio'=>'San Andrés',         'dpto'=>'Archipiélago de San Andrés, Providencia y Santa Catalina', 'sub'=>null],
    'SAN ANDRES ISLAS'           => ['mpio'=>'San Andrés',         'dpto'=>'Archipiélago de San Andrés, Providencia y Santa Catalina', 'sub'=>null],
    'SAN ANDRES Y PROVIDENCIA'   => ['mpio'=>'San Andrés',         'dpto'=>'Archipiélago de San Andrés, Providencia y Santa Catalina', 'sub'=>null],
    // Leticia
    'LETICIA'                    => ['mpio'=>'Leticia',            'dpto'=>'Amazonas',            'sub'=>null],
    // Mocoa
    'MOCOA'                      => ['mpio'=>'Mocoa',              'dpto'=>'Putumayo',            'sub'=>null],
    // Mitú + sin tilde
    'MITU'                       => ['mpio'=>'Mitú',               'dpto'=>'Vaupés',              'sub'=>null],
    // Inírida
    'PUERTO INIRIDA'             => ['mpio'=>'Puerto Inírida',     'dpto'=>'Guainía',             'sub'=>null],
    // San José del Guaviare
    'SAN JOSE DEL GUAVIARE'      => ['mpio'=>'San José del Guaviare','dpto'=>'Guaviare',          'sub'=>null],
    // Municipios del área metropolitana de Medellín
    'BELLO'                      => ['mpio'=>'Bello',              'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'ENVIGADO'                   => ['mpio'=>'Envigado',           'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'ITAGUI'                     => ['mpio'=>'Itagüí',             'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'ITAGÜÍ'                     => ['mpio'=>'Itagüí',             'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'SABANETA'                   => ['mpio'=>'Sabaneta',           'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'LA ESTRELLA'                => ['mpio'=>'La Estrella',        'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'CALDAS'                     => ['mpio'=>'Caldas',             'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'COPACABANA'                 => ['mpio'=>'Copacabana',         'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'GIRARDOTA'                  => ['mpio'=>'Girardota',          'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'BARBOSA'                    => ['mpio'=>'Barbosa',            'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
];

// Alias de subregiones: nombres coloquiales/erróneos → nombre c98_geo
const SUBR_ALIAS = [
    'AREA METROPOLITANA'   => ['dpto'=>'Antioquia',    'sub'=>'Valle de Aburrá'],
    'AREA METROPOLITAN'    => ['dpto'=>'Antioquia',    'sub'=>'Valle de Aburrá'],
    'VALLE DEL ABURRA'     => ['dpto'=>'Antioquia',    'sub'=>'Valle de Aburrá'],
    'VALLE DE ABURRA'      => ['dpto'=>'Antioquia',    'sub'=>'Valle de Aburrá'],
    'ABURRA'               => ['dpto'=>'Antioquia',    'sub'=>'Valle de Aburrá'],
    'ABURRÁ'               => ['dpto'=>'Antioquia',    'sub'=>'Valle de Aburrá'],
    'METROPOLITANA'        => ['dpto'=>'Antioquia',    'sub'=>'Valle de Aburrá'],
    // Santander
    'SOTO'                 => ['dpto'=>'Santander',    'sub'=>'Soto'],
    // Bogotá
    'CAPITAL'              => ['dpto'=>'Bogotá D.C.',  'sub'=>'Bogotá D.C.'],
    // Bolívar
    'DIQUE BOLIVARENSE'    => ['dpto'=>'Bolívar',      'sub'=>'Dique Bolivarense'],
    'MONTES DE MARIA'      => ['dpto'=>'Sucre',        'sub'=>'Montes de María'],
    'MONTES DE MARÍA'      => ['dpto'=>'Sucre',        'sub'=>'Montes de María'],
];

function getGeo(): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    $db = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Detectar BD geo
    $dbs = $db->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($dbs as $d) {
        if (strtoupper($d) === 'C98_GEO') { $db->exec("USE `$d`"); return $db; }
    }
    throw new RuntimeException('Base de datos C98_GEO no encontrada');
}

$run = isset($_POST['run']);

if ($run):
    ob_start();
    try {
        $db  = getDB();
        $geo = getGeo();

        // ── PASO 1: Detectar municipios mal clasificados en columna subregion ──────
        echo "<div class='paso'><div class='paso-title'>── PASO 1: Detectar municipios en columna subregión ──</div>";

        // Obtener todos los municipios conocidos de c98_geo — clave sin tildes
        $mpioRows = $geo->query("SELECT nombre AS nom_orig FROM dim_municipio")
                         ->fetchAll(PDO::FETCH_ASSOC);
        $mpioSet = []; // [normStr(nombre) => nombre_original]
        foreach ($mpioRows as $r) $mpioSet[normStr($r['nom_orig'])] = $r['nom_orig'];

        // Buscar registros donde subregion coincide con nombre de municipio (sin tildes)
        $stMal = $db->query("SELECT subregion AS sr_raw, UPPER(TRIM(subregion)) AS sr, COUNT(*) AS cnt
                              FROM import_log_leads
                              WHERE subregion IS NOT NULL
                              GROUP BY subregion");
        // malClasif: [canonical => [raw_value => count, ...]]
        // Guardamos el raw para usar WHERE subregion = ? exacto (sin UPPER, sin charset issues)
        $malClasif = [];
        foreach ($stMal as $r) {
            $key = normStr($r['sr_raw']);
            $canonical = null;
            if (isset($mpioSet[$key])) {
                $canonical = $mpioSet[$key]; // dim_municipio c98_geo
            } elseif (isset(MPIO_ALIASES[$key])) {
                $canonical = MPIO_ALIASES[$key]['mpio']; // alias explícito (cubre charset issues)
            }
            if ($canonical !== null) {
                if (!isset($malClasif[$canonical])) $malClasif[$canonical] = [];
                $malClasif[$canonical][$r['sr_raw']] = (int)$r['cnt'];
            }
        }

        if (empty($malClasif)) {
            echo "<div class='line ok'>✅ No se encontraron municipios mal clasificados en subregion</div>";
        } else {
            foreach ($malClasif as $nom => $raws) {
                $total = array_sum($raws);
                echo "<div class='line warn'>⚠️ <code>$nom</code> aparece en columna subregión → <span class='kpi'>$total registros</span></div>";
            }

            // Corregir: usar el valor raw exacto en WHERE — evita problema de UPPER() con tildes en MySQL
            $fixedMal = 0;
            foreach ($malClasif as $nomMpio => $raws) {
                foreach ($raws as $rawVal => $cnt) {
                    // rawNorm: versión sin tildes del valor en subregion
                    // Permite detectar que municipio = "Bogota" es igual a subregion = "Bogota"
                    $rawNorm = normStr($rawVal);
                    $st = $db->prepare("
                        UPDATE import_log_leads
                        SET municipio = COALESCE(NULLIF(TRIM(municipio),''), ?),
                            subregion = NULL,
                            geo_departamento = COALESCE(NULLIF(TRIM(geo_departamento),''), ?)
                        WHERE subregion = ?
                          AND (
                            municipio IS NULL OR TRIM(municipio) = ''
                            OR TRIM(municipio) = ?
                            OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                               TRIM(municipio),'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u')) = ?
                          )
                    ");
                    $dpto = MPIO_ALIASES[$rawNorm]['dpto'] ?? null;
                    $st->execute([$nomMpio, $dpto, $rawVal, $nomMpio, $rawNorm]);
                    $aff = $st->rowCount();
                    if ($aff > 0) {
                        echo "<div class='line ok'>  ↳ <code>$rawVal</code> → <code>municipio=$nomMpio</code>: $aff filas</div>";
                        $fixedMal += $aff;
                    }
                }
            }
            echo "<div class='line ok'>📊 Total corregidos PASO 1: <b>$fixedMal</b> filas</div>";
        }
        echo "</div>";

        // ── PASO 2: Rellenar geo_departamento desde subregion vía c98_geo ──────────
        echo "<div class='paso'><div class='paso-title'>── PASO 2: Rellenar geo_departamento desde subregión (c98_geo) ──</div>";

        // Mapa subregion → departamento + nombre canónico desde c98_geo (clave sin tildes)
        $subregMap = $geo->query("
            SELECT s.nombre AS nom_sub, d.nombre AS nom_dpto
            FROM dim_subregion s
            JOIN dim_departamento d ON d.id = s.id_departamento
        ")->fetchAll(PDO::FETCH_ASSOC);

        $subregDpto = []; // [normStr(sub) => [dpto, sub_canonico]]
        foreach ($subregMap as $r) {
            $subregDpto[normStr($r['nom_sub'])] = ['dpto'=>$r['nom_dpto'], 'sub'=>$r['nom_sub']];
        }
        // Agregar alias definidos arriba
        foreach (SUBR_ALIAS as $alias => $info) {
            if ($info) $subregDpto[normStr($alias)] = $info;
        }

        // Subregiones únicas en leads donde geo_departamento es NULL
        $pendientes = $db->query("
            SELECT subregion AS sr_raw, COUNT(*) AS cnt
            FROM import_log_leads
            WHERE subregion IS NOT NULL AND geo_departamento IS NULL
            GROUP BY subregion
            ORDER BY cnt DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pendientes)) {
            echo "<div class='line ok'>✅ Todos los registros con subregión ya tienen geo_departamento</div>";
        } else {
            $totalP2 = 0;
            $sinMatch = [];
            foreach ($pendientes as $p) {
                $srRaw  = $p['sr_raw'];
                $srNorm = normStr($srRaw);
                $cnt    = (int)$p['cnt'];

                // 1. Coincidencia exacta (sin tildes)
                if (isset($subregDpto[$srNorm])) {
                    $info = $subregDpto[$srNorm];
                }
                // 2. Fuzzy levenshtein — captura typos como OCCIDETNE → OCCIDENTE
                else {
                    $bestKey = null; $bestDist = PHP_INT_MAX;
                    foreach ($subregDpto as $k => $v) {
                        $d = levenshtein($srNorm, $k);
                        $maxLen = max(strlen($srNorm), strlen($k));
                        if ($d < $bestDist && $d <= max(2, (int)($maxLen * 0.2))) {
                            $bestDist = $d; $bestKey = $k; $info = $v;
                        }
                    }
                    if (!$bestKey) { $sinMatch[$srRaw] = $cnt; continue; }
                    echo "<div class='line warn'>  🔤 Typo corregido: <code>$srRaw</code> → <code>{$info['sub']}</code> (dist=$bestDist)</div>";
                }

                $dpto    = $info['dpto'];
                $subCan  = $info['sub'] ?? null;
                $st = $db->prepare("
                    UPDATE import_log_leads
                    SET geo_departamento = ?,
                        subregion = COALESCE(NULLIF(subregion,''), ?)
                    WHERE geo_departamento IS NULL
                      AND subregion = ?
                ");
                $st->execute([$dpto, $subCan, $srRaw]);
                $aff = $st->rowCount();
                if ($aff > 0) {
                    echo "<div class='line ok'>  ✅ <code>$srRaw</code> → <b>$dpto</b>: $aff filas</div>";
                    $totalP2 += $aff;
                }
            }
            foreach ($sinMatch as $sr => $cnt)
                echo "<div class='line warn'>  ⚠️ Sin match: <code>$sr</code> ($cnt registros)</div>";

            echo "<div class='line ok'>📊 Total actualizados PASO 2: <b>$totalP2</b> filas</div>";
        }
        echo "</div>";

        // ── PASO 3: Rellenar geo_departamento desde municipio vía dim_geo ───────────
        // Usa dim_geo (C98_GET, misma conexión y charset) en lugar de c98_geo
        // para evitar problemas de charset y columnas distintas.
        echo "<div class='paso'><div class='paso-title'>── PASO 3: Rellenar geo_departamento desde municipio (dim_geo) ──</div>";

        // Cargar todos los municipios de dim_geo — clave = abr_mpio (ya normalizado)
        $dimGeoRows = $db->query("
            SELECT DISTINCT abr_mpio, nom_mpio, nom_dpto, nom_subregion
            FROM dim_geo WHERE nivel = 4 AND abr_mpio IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);

        $mpioDpto = []; $mpioSub = [];
        foreach ($dimGeoRows as $r) {
            $k = $r['abr_mpio'] ?: normStr($r['nom_mpio']);
            $mpioDpto[$k] = $r['nom_dpto'];
            if (!empty($r['nom_subregion'])) $mpioSub[$k] = $r['nom_subregion'];
        }

        // MPIO_ALIASES (const definida arriba) actúa como primera línea de lookup

        // Municipios únicos donde geo_departamento es NULL
        $pendM = $db->query("
            SELECT municipio AS mn_raw, COUNT(*) AS cnt
            FROM import_log_leads
            WHERE municipio IS NOT NULL AND geo_departamento IS NULL
            GROUP BY municipio
            ORDER BY cnt DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $totalP3 = 0; $sinMatchM = []; $comunasReclasif = 0;
        foreach ($pendM as $p) {
            $mnRaw  = $p['mn_raw'];
            $mnNorm = normStr($mnRaw);
            $cnt    = (int)$p['cnt'];

            // ── Detectar comunas mal clasificadas en municipio (empiezan con número)
            if (preg_match('/^\d+\s/', $mnNorm)) {
                $stC = $db->prepare("
                    UPDATE import_log_leads
                    SET comuna_localidad = COALESCE(NULLIF(TRIM(comuna_localidad),''), municipio),
                        municipio = NULL
                    WHERE municipio = ?
                      AND (comuna_localidad IS NULL OR TRIM(comuna_localidad) = '')
                ");
                $stC->execute([$mnRaw]);
                $affC = $stC->rowCount();
                if ($affC > 0) {
                    echo "<div class='line ok'>  ♻️ <code>$mnRaw</code> → movido a <code>comuna_localidad</code>: $affC filas</div>";
                    $comunasReclasif += $affC;
                }
                continue;
            }

            // 0. MPIO_ALIASES — lookup hardcoded, máxima fiabilidad (sin dependencia de charset)
            $matchAlias = null; $dpto = null; $sub = null;
            if (isset(MPIO_ALIASES[$mnNorm])) {
                $matchAlias = MPIO_ALIASES[$mnNorm];
                $dpto = $matchAlias['dpto'];
                $sub  = $matchAlias['sub'];
                echo "<div class='line ok'>  🏷 Alias: <code>$mnRaw</code> → <b>$dpto</b></div>";
            }
            // 1. dim_geo exacto (abr_mpio normalizado)
            elseif (isset($mpioDpto[$mnNorm])) {
                $dpto = $mpioDpto[$mnNorm];
                $sub  = $mpioSub[$mnNorm] ?? null;
            }
            // 2. Fuzzy levenshtein — typos como MANIZALEZ → MANIZALES, RIOACHA → RIOHACHA
            else {
                $bestKey = null; $bestDist = PHP_INT_MAX;
                foreach ($mpioDpto as $k => $v) {
                    $d = levenshtein($mnNorm, $k);
                    $maxLen = max(strlen($mnNorm), strlen($k));
                    if ($d < $bestDist && $d <= max(2, (int)($maxLen * 0.2))) {
                        $bestDist = $d; $bestKey = $k;
                    }
                }
                if ($bestKey) {
                    $dpto = $mpioDpto[$bestKey];
                    $sub  = $mpioSub[$bestKey] ?? null;
                    echo "<div class='line warn'>  🔤 Typo: <code>$mnRaw</code> → <code>$bestKey</code> (dist=$bestDist)</div>";
                } else {
                    $sinMatchM[$mnRaw] = $cnt;
                    continue;
                }
            }

            $st = $db->prepare("
                UPDATE import_log_leads
                SET geo_departamento = ?,
                    subregion = COALESCE(subregion, ?)
                WHERE geo_departamento IS NULL
                  AND municipio = ?
            ");
            $st->execute([$dpto, $sub, $mnRaw]);
            $aff = $st->rowCount();
            if ($aff > 0) {
                $subTag = $sub ? " · sub → <code>$sub</code>" : '';
                echo "<div class='line ok'>  ✅ <code>$mnRaw</code> → <b>$dpto</b>$subTag: $aff filas</div>";
                $totalP3 += $aff;
            }
        }
        if ($comunasReclasif > 0)
            echo "<div class='line ok'>  ♻️ Comunas reclasificadas: <b>$comunasReclasif</b></div>";
        foreach ($sinMatchM as $mn => $cnt)
            echo "<div class='line warn'>  ⚠️ Sin match: <code>$mn</code> ($cnt registros)</div>";

        echo "<div class='line ok'>📊 Total actualizados PASO 3: <b>$totalP3</b> filas</div>";
        echo "</div>";

        // ── PASO 4: Rellenar subregion desde municipio donde es NULL ─────────────
        echo "<div class='paso'><div class='paso-title'>── PASO 4: Rellenar subregion desde municipio (dim_geo) ──</div>";

        // Reutiliza $mpioSub con claves normStr (abr_mpio) ya construido en PASO 3
        $pendSub = $db->query("
            SELECT municipio AS mn_raw, COUNT(*) AS cnt
            FROM import_log_leads
            WHERE municipio IS NOT NULL AND subregion IS NULL
            GROUP BY municipio
            ORDER BY cnt DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);

        $totalP4 = 0;
        foreach ($pendSub as $p) {
            $mnRaw  = $p['mn_raw'];
            $mnNorm = normStr($mnRaw); // normStr garantiza clave sin tildes
            // Resolver key: exacto → alias → fuzzy (mismo orden que PASO 3)
            $matchKey = null;
            // MPIO_ALIASES primero — si conocemos la subregión, usarla directamente
            if (isset(MPIO_ALIASES[$mnNorm]) && !empty(MPIO_ALIASES[$mnNorm]['sub'])) {
                $sub = MPIO_ALIASES[$mnNorm]['sub'];
                $st  = $db->prepare("UPDATE import_log_leads SET subregion = ? WHERE subregion IS NULL AND municipio = ?");
                $st->execute([$sub, $mnRaw]);
                $aff = $st->rowCount();
                if ($aff > 0) {
                    echo "<div class='line ok'>  ✅ <code>$mnRaw</code> → subregión <b>$sub</b>: $aff filas</div>";
                    $totalP4 += $aff;
                }
                continue;
            }
            if (isset($mpioSub[$mnNorm])) {
                $matchKey = $mnNorm;
            } else {
                $bestKey = null; $bestDist = PHP_INT_MAX;
                foreach ($mpioSub as $k => $v) {
                    $d = levenshtein($mnNorm, $k);
                    $maxLen = max(strlen($mnNorm), strlen($k));
                    if ($d < $bestDist && $d <= max(2, (int)($maxLen * 0.2)))
                        { $bestDist = $d; $bestKey = $k; }
                }
                if ($bestKey) $matchKey = $bestKey;
            }
            if (!$matchKey) continue;

            $sub = $mpioSub[$matchKey];
            $st  = $db->prepare("
                UPDATE import_log_leads
                SET subregion = ?
                WHERE subregion IS NULL AND municipio = ?
            ");
            $st->execute([$sub, $mnRaw]);
            $aff = $st->rowCount();
            if ($aff > 0) {
                echo "<div class='line ok'>  ✅ <code>$mnRaw</code> → subregión <b>$sub</b>: $aff filas</div>";
                $totalP4 += $aff;
            }
        }
        if ($totalP4 === 0) echo "<div class='line mut'>  ▸ Sin actualizaciones en subregión</div>";
        echo "<div class='line ok'>📊 Total actualizados PASO 4: <b>$totalP4</b> filas</div>";
        echo "</div>";

        // ── RESUMEN FINAL ─────────────────────────────────────────────────────────
        $res = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(geo_departamento IS NOT NULL) AS con_dpto,
                SUM(municipio IS NOT NULL) AS con_mpio,
                SUM(subregion IS NOT NULL) AS con_sub,
                SUM(zona IS NOT NULL) AS con_zona,
                SUM(comuna_localidad IS NOT NULL) AS con_comuna
            FROM import_log_leads
        ")->fetch(PDO::FETCH_ASSOC);

        $stC = (int)$res['con_comuna'];

        $total = max(1,(int)$res['total']);
        echo "<div class='paso'><div class='paso-title'>── VERIFICACIÓN FINAL ──</div>";
        echo "<div class='line'>Total leads: <span class='kpi'>$total</span></div>";
        $fields = [
            'Departamento' => $res['con_dpto'],
            'Municipio'    => $res['con_mpio'],
            'Subregión'    => $res['con_sub'],
            'Zona'         => $res['con_zona'],
            'Comuna'       => $stC,
        ];
        foreach ($fields as $name => $cnt) {
            $pct  = round($cnt / $total * 100, 1);
            $cls  = $pct >= 80 ? 'ok' : ($pct >= 40 ? 'warn' : 'err');
            echo "<div class='line'>$name: <span class='kpi'>$cnt</span> <span class='$cls'>($pct%)</span></div>";
        }
        echo "</div>";

    } catch (Exception $e) {
        echo "<div class='paso'><div class='line err'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div></div>";
    }
endif;
?>

<form method="post" style="margin-bottom:16px">
  <button type="submit" name="run" value="1" class="run-btn">
    ▶ Ejecutar Enriquecimiento Geográfico
  </button>
</form>

<?php if (!$run): ?>
<div class="paso">
  <div class="paso-title">Qué hace este script</div>
  <div class="line"><b>PASO 1</b> — Detecta nombres de municipio en la columna <code>subregion</code> y los mueve a <code>municipio</code></div>
  <div class="line"><b>PASO 2</b> — Rellena <code>geo_departamento</code> cuando <code>subregion</code> está presente usando c98_geo</div>
  <div class="line"><b>PASO 3</b> — Rellena <code>geo_departamento</code> + <code>subregion</code> cuando <code>municipio</code> está presente</div>
  <div class="line"><b>PASO 4</b> — Rellena <code>subregion</code> vacíos usando el municipio como referencia</div>
  <div class="line" style="margin-top:8px"><span class="warn">⚠️</span> Operación idempotente — ejecutar múltiples veces es seguro</div>
</div>
<?php endif; ?>

<div style="margin-top:16px;color:#7b84a3;font-size:11px">
  ← <a href="/get/leads_analytics.html" style="color:#818cf8">Dashboard Analytics</a> ·
  <a href="/get/_migration_id_lead.php" style="color:#818cf8">Migración</a>
</div>
</body>
</html>
