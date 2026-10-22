<?php
/**
 * c98geo_api.php — Jerarquía geográfica completa
 * Niveles: Región → Dpto → Subregión → Municipio → Zona → Comuna → Barrio
 * Fuente única: c98_geo (dim_region..dim_barrio, por FK real). No usa medfut/dim_geo.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

define('C98_DB_HOST', '127.0.0.1');
define('C98_DB_PORT', 3307);
define('C98_DB_NAME', 'c98_geo');
define('C98_DB_USER', 'root');
define('C98_DB_PASS', '');

function getC98DB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            C98_DB_HOST, C98_DB_PORT, C98_DB_NAME);
        $pdo = new PDO($dsn, C98_DB_USER, C98_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

try {
    $db = getC98DB();

    // ── 1. Traer las 7 tablas completas de una vez (volumen manejable) ──────────
    $regiones = $db->query("SELECT id, nombre FROM dim_region ORDER BY nombre")->fetchAll();

    $deptos = $db->query("SELECT id, nombre, id_region FROM dim_departamento ORDER BY nombre")->fetchAll();

    $subregiones = $db->query("SELECT id, nombre, id_departamento FROM dim_subregion ORDER BY nombre")->fetchAll();

    $municipios = $db->query("
        SELECT id, nombre, abreviatura, id_departamento, id_subregion
        FROM dim_municipio ORDER BY nombre
    ")->fetchAll();

    $zonas = $db->query("SELECT id, nombre, id_municipio FROM dim_zona ORDER BY nombre")->fetchAll();

    $comunas = $db->query("SELECT id, nombre, id_zona FROM dim_comuna ORDER BY nombre")->fetchAll();

    $barrios = $db->query("SELECT id, nombre, id_comuna FROM dim_barrio ORDER BY nombre")->fetchAll();

    // ── 2. Indexar hijos por padre (agrupar por FK real) ─────────────────────────
    $deptosPorRegion = [];
    foreach ($deptos as $d) $deptosPorRegion[$d['id_region']][] = $d;

    $subrPorDepto = [];
    foreach ($subregiones as $s) $subrPorDepto[$s['id_departamento']][] = $s;

    $mpioPorSubr = [];
    foreach ($municipios as $m) $mpioPorSubr[$m['id_subregion']][] = $m;

    $zonaPorMpio = [];
    foreach ($zonas as $z) $zonaPorMpio[$z['id_municipio']][] = $z;

    $comunaPorZona = [];
    foreach ($comunas as $c) $comunaPorZona[$c['id_zona']][] = $c;

    $barrioPorComuna = [];
    foreach ($barrios as $b) $barrioPorComuna[$b['id_comuna']][] = $b;

    // ── 3. Construir de abajo hacia arriba: barrio → comuna → zona → municipio ──
    // Cada nivel lleva su 'id' real de c98_geo — el frontend lo usa para
    // sincronizar con el mapa por id exacto (evita colisiones de nombre, ej.
    // "La Candelaria" existe como comuna en Bogota Y en Medellin).
    $buildComuna = function($comuna) use ($barrioPorComuna) {
        $barsRaw = $barrioPorComuna[$comuna['id']] ?? [];
        usort($barsRaw, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));
        $bars = array_map(fn($b) => ['id' => $b['id'], 'n' => $b['nombre']], $barsRaw);
        return ['id' => $comuna['id'], 'c' => $comuna['nombre'], 'n_bar' => count($bars), 'bars' => $bars];
    };

    $buildZona = function($zona) use ($comunaPorZona, $buildComuna) {
        $coms = array_map($buildComuna, $comunaPorZona[$zona['id']] ?? []);
        $nBar = array_sum(array_column($coms, 'n_bar'));
        return ['id' => $zona['id'], 'z' => mb_strtoupper($zona['nombre'], 'UTF-8'), 'n_com' => count($coms), 'coms' => $coms, '_n_bar' => $nBar];
    };

    $buildMunicipio = function($mpio) use ($zonaPorMpio, $buildZona) {
        $zonasHijas = $zonaPorMpio[$mpio['id']] ?? [];
        $detailZonas = array_map($buildZona, $zonasHijas);
        $nComunas = array_sum(array_column($detailZonas, 'n_com'));
        $nBarrios = array_sum(array_column($detailZonas, '_n_bar'));
        $tieneDetalle = $nComunas > 0; // solo mostrar expandible si hay algo mas alla de la zona
        foreach ($detailZonas as &$z) unset($z['_n_bar']);
        unset($z);
        return [
            'id'      => $mpio['id'],
            'm'       => $mpio['nombre'],
            'abr'     => $mpio['abreviatura'] ?? '',
            'zonas'   => count($zonasHijas),
            'comunas' => $nComunas,
            'barrios' => $nBarrios,
            'detail'  => $tieneDetalle ? $detailZonas : null,
        ];
    };

    // ── 4. Construir de arriba hacia abajo: región → depto → subregión → mpios ──
    $result = [];
    foreach ($regiones as $reg) {
        $dptosHijos = $deptosPorRegion[$reg['id']] ?? [];
        $dSalida = [];
        foreach ($dptosHijos as $dpto) {
            $subrHijas = $subrPorDepto[$dpto['id']] ?? [];
            $sSalida = [];
            foreach ($subrHijas as $subr) {
                $mpiosHijos = $mpioPorSubr[$subr['id']] ?? [];
                $mSalida = array_map($buildMunicipio, $mpiosHijos);
                $sBar = array_sum(array_column($mSalida, 'barrios'));
                $sCom = array_sum(array_column($mSalida, 'comunas'));
                $sSalida[] = [
                    'id' => $subr['id'], 's' => mb_strtoupper($subr['nombre'], 'UTF-8'),
                    'n' => count($mSalida), 'barrios' => $sBar, 'comunas' => $sCom,
                    'mpios' => $mSalida,
                ];
            }
            $dMpios = array_sum(array_column($sSalida, 'n'));
            $dBar   = array_sum(array_column($sSalida, 'barrios'));
            $dCom   = array_sum(array_column($sSalida, 'comunas'));
            $dSalida[] = [
                'id' => $dpto['id'], 'd' => $dpto['nombre'], 'n_subrs' => count($sSalida), 'n_mpios' => $dMpios,
                'barrios' => $dBar, 'comunas' => $dCom, 'subrs' => $sSalida,
            ];
        }
        usort($dSalida, fn($a, $b) => strcmp($a['d'], $b['d']));
        $result[] = [
            'id' => $reg['id'], 'r' => $reg['nombre'],
            'n_dptos' => count($dSalida),
            'n_subrs' => array_sum(array_column($dSalida, 'n_subrs')),
            'n_mpios' => array_sum(array_column($dSalida, 'n_mpios')),
            'barrios' => array_sum(array_column($dSalida, 'barrios')),
            'comunas' => array_sum(array_column($dSalida, 'comunas')),
            'dptos'   => $dSalida,
        ];
    }

    // Orden de regiones acorde al resto del dashboard
    // (ojo: array_search puede devolver 0, que es "falsy" — no usar ?: aqui)
    $ord = ['Andina', 'Eje Cafetero', 'Caribe', 'Pacífico', 'Orinoquía', 'Amazonía'];
    $ordPos = fn($r) => (($p = array_search($r, $ord)) !== false) ? $p : 99;
    usort($result, fn($a, $b) => $ordPos($a['r']) <=> $ordPos($b['r']));

    echo json_encode(['ok' => true, 'ts' => time(), 'data' => $result],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
