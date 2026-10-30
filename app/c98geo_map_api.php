<?php
/**
 * c98geo_map_api.php — Geometrías reales para el mapa
 * Fuente: c98_geo.geo_geometry (independiente de C98_GET.dim_geo)
 * Uso: c98geo_map_api.php?tipo=departamento&resolution=mid[&padre=05]
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

// tabla de entidad por nivel, y columnas FK validas para filtrar por padre
// (algunos niveles tienen mas de un padre posible segun desde donde se haga drill-down)
const NIVELES = [
    'region'       => ['tabla' => 'dim_region',       'padres' => []],
    'departamento' => ['tabla' => 'dim_departamento', 'padres' => ['id_region']],
    'subregion'    => ['tabla' => 'dim_subregion',    'padres' => ['id_departamento']],
    'municipio'    => ['tabla' => 'dim_municipio',    'padres' => ['id_departamento','id_subregion']],
    'zona'         => ['tabla' => 'dim_zona',         'padres' => ['id_municipio']],
    'comuna'       => ['tabla' => 'dim_comuna',       'padres' => ['id_zona']],
    'barrio'       => ['tabla' => 'dim_barrio',       'padres' => ['id_comuna']],
];

// tabla de entidad correspondiente a cada columna FK (para poder unir con el
// padre y filtrar por su nombre, ej: desambiguar subregion "NORTE" por depto)
const TABLA_POR_FK = [
    'id_region'       => 'dim_region',
    'id_departamento' => 'dim_departamento',
    'id_subregion'    => 'dim_subregion',
    'id_municipio'    => 'dim_municipio',
    'id_zona'         => 'dim_zona',
    'id_comuna'       => 'dim_comuna',
];

// resolución disponible por nivel, en orden de preferencia si la pedida no existe
const RES_FALLBACK = [
    'region'       => ['low','mid','high'],
    'departamento' => ['low','mid','high'],
    'subregion'    => ['low','mid','high'],
    'municipio'    => ['mid','high','low'],
    'zona'         => ['high'],
    'comuna'       => ['high'],
    'barrio'       => ['high'],
];

try {
    $tipo = $_GET['tipo'] ?? '';
    if (!isset(NIVELES[$tipo])) {
        throw new InvalidArgumentException("tipo inválido: $tipo");
    }
    $resSolicitada = $_GET['resolution'] ?? RES_FALLBACK[$tipo][0];
    $padre = $_GET['padre'] ?? null;
    $nombre = isset($_GET['nombre']) && $_GET['nombre'] !== '' ? $_GET['nombre'] : null;
    $padreNombre = isset($_GET['padre_nombre']) && $_GET['padre_nombre'] !== '' ? $_GET['padre_nombre'] : null;
    $idExacto = isset($_GET['id']) && $_GET['id'] !== '' ? $_GET['id'] : null;

    // ids=05001,08001,...  → devuelve solo esas entidades (para pintar únicamente las
    // áreas con registros de un segmento, sin traer la geometría nacional completa).
    $idsLista = [];
    if (isset($_GET['ids']) && $_GET['ids'] !== '') {
        foreach (explode(',', $_GET['ids']) as $rawId) {
            $rawId = trim($rawId);
            if ($rawId !== '' && preg_match('/^[0-9A-Za-z]+$/', $rawId)) {
                $idsLista[] = $rawId;
            }
        }
        $idsLista = array_slice(array_values(array_unique($idsLista)), 0, 800);
    }

    // punto=lon,lat — busca que entidad de este nivel contiene ese punto
    // (usado por el zoom automatico del mapa: al cambiar de nivel, ubica el
    // padre correspondiente al centro actual del mapa via ST_Contains)
    $puntoLon = null; $puntoLat = null;
    if (isset($_GET['punto']) && $_GET['punto'] !== '') {
        $partes = explode(',', $_GET['punto']);
        if (count($partes) === 2 && is_numeric($partes[0]) && is_numeric($partes[1])) {
            $puntoLon = (float)$partes[0];
            $puntoLat = (float)$partes[1];
        }
    }

    $db = getC98DB();
    $cfg = NIVELES[$tipo];

    // padre_campo debe ser EXACTAMENTE uno de los nombres de columna permitidos
    // para este nivel (whitelist NIVELES[$tipo]['padres']) — nunca se usa el
    // valor del request sin esta validacion, para evitar inyectar un nombre de
    // columna arbitrario en el SQL.
    $padreCampoSolicitado = $_GET['padre_campo'] ?? null;
    $padreCampo = null;
    if ($padre !== null && count($cfg['padres']) > 0) {
        if ($padreCampoSolicitado !== null && in_array($padreCampoSolicitado, $cfg['padres'], true)) {
            $padreCampo = $padreCampoSolicitado;
        } else {
            $padreCampo = $cfg['padres'][0];
        }
    }

    // resolver que resolucion usar realmente (la pedida, o la primera disponible en fallback)
    $stmtRes = $db->prepare("SELECT DISTINCT resolution FROM geo_geometry WHERE tipo = :tipo");
    $stmtRes->execute(['tipo' => $tipo]);
    $disponibles = $stmtRes->fetchAll(PDO::FETCH_COLUMN);
    $resolution = in_array($resSolicitada, $disponibles, true)
        ? $resSolicitada
        : (RES_FALLBACK[$tipo][0] ?? 'high');
    if (!in_array($resolution, $disponibles, true) && count($disponibles) > 0) {
        $resolution = $disponibles[0];
    }

    // si se pide desambiguar por nombre del padre, resolver la columna FK y su
    // tabla (whitelist TABLA_POR_FK) y armar el JOIN correspondiente
    $joinPadre = '';
    $campoParaPadreNombre = $padreCampo ?? ($cfg['padres'][0] ?? null);
    if ($padreNombre !== null && $campoParaPadreNombre !== null && isset(TABLA_POR_FK[$campoParaPadreNombre])) {
        $tablaPadre = TABLA_POR_FK[$campoParaPadreNombre];
        $joinPadre = " JOIN {$tablaPadre} pe ON pe.id = e.{$campoParaPadreNombre} ";
    } else {
        $padreNombre = null; // no hay como resolver el join, se ignora el filtro
    }

    $sql = "
        SELECT e.id AS id, e.nombre AS nombre, ST_AsGeoJSON(g.geom) AS geojson,
               g.centroid_lat AS lat, g.centroid_lon AS lon
        FROM {$cfg['tabla']} e
        JOIN geo_geometry g ON g.id_tipo = e.id AND g.tipo = :tipo AND g.resolution = :resolution
        {$joinPadre}
        WHERE g.geom IS NOT NULL
    ";
    $params = ['tipo' => $tipo, 'resolution' => $resolution];

    if ($padreNombre !== null) {
        $sql .= " AND (pe.nombre LIKE CONCAT('%', :pnombre1, '%') OR :pnombre2 LIKE CONCAT('%', pe.nombre, '%'))";
        $params['pnombre1'] = $padreNombre;
        $params['pnombre2'] = $padreNombre;
    }

    if ($padreCampo !== null) {
        // $padreCampo ya fue validado arriba contra la whitelist NIVELES[$tipo]['padres']
        $sql .= " AND e.{$padreCampo} = :padre";
        $params['padre'] = $padre;
    }
    // busqueda por nombre — usada para sincronizar la tabla (otra fuente de datos,
    // sin id en comun) con el mapa. Los dos catalogos formatean nombres distinto
    // (ej. "Bogota" vs "Bogotá, D.C."), asi que se hace match bidireccional por
    // contencion de substring, insensible a mayus/acentos via collation utf8mb4_unicode_ci.
    if ($nombre !== null) {
        $sql .= " AND (e.nombre LIKE CONCAT('%', :nombre1, '%') OR :nombre2 LIKE CONCAT('%', e.nombre, '%'))";
        $params['nombre1'] = $nombre;
        $params['nombre2'] = $nombre;
    }
    // match exacto por id — usado por la tabla ahora que tabla y mapa comparten
    // la misma fuente (c98_geo). Prioridad sobre nombre/padre_nombre: sin ambiguedad
    // posible, evita colisiones como "La Candelaria" (existe en Bogota Y en Medellin).
    if ($idExacto !== null) {
        $sql .= " AND e.id = :id_exacto";
        $params['id_exacto'] = $idExacto;
    }
    if (!empty($idsLista)) {
        $ph = [];
        foreach ($idsLista as $i => $val) {
            $ph[] = ":id_l$i";
            $params["id_l$i"] = $val;
        }
        $sql .= " AND e.id IN (" . implode(',', $ph) . ")";
    }
    if ($puntoLon !== null && $puntoLat !== null) {
        $sql .= " AND ST_Contains(g.geom, ST_PointFromText(CONCAT('POINT(', :plon, ' ', :plat, ')'), 4326))";
        $params['plon'] = $puntoLon;
        $params['plat'] = $puntoLat;
    }
    $sql .= " ORDER BY e.nombre";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $features = [];
    foreach ($rows as $r) {
        $features[] = [
            'type'       => 'Feature',
            'properties' => [
                'id'     => $r['id'],
                'nombre' => $r['nombre'],
                'lat'    => $r['lat'] !== null ? (float)$r['lat'] : null,
                'lon'    => $r['lon'] !== null ? (float)$r['lon'] : null,
            ],
            'geometry'   => json_decode($r['geojson'], true),
        ];
    }

    echo json_encode([
        'ok'         => true,
        'tipo'       => $tipo,
        'resolution' => $resolution,
        'count'      => count($features),
        'data'       => ['type' => 'FeatureCollection', 'features' => $features],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
