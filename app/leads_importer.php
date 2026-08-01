<?php
// ============================================================
//  MEDFUT · Importador de Leads Meta Ads  (backend PHP nativo)
//  URL base: http://localhost/medfut/leads_importer.php
//
//  Acciones:
//    ?action=check        — Verifica dependencias PHP
//    ?action=get_sheets   — Recibe archivo, devuelve hojas
//    ?action=preview      — Cabeceras detectadas + muestra
//    ?action=import       — Importa todos los registros
//    ?action=logs         — Historial de importaciones
// ============================================================

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

set_time_limit(120);

define('TMP_DIR', __DIR__ . '/tmp/');
if (!is_dir(TMP_DIR)) {
    mkdir(TMP_DIR, 0750, true);
    file_put_contents(TMP_DIR . '.htaccess', "Deny from all\n");
}

require_once __DIR__ . '/ExcelReaderPhp.php';

// ── Helpers ──────────────────────────────────────────────────
function jsonOut($data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
function errOut(string $msg, int $code = 400): void {
    http_response_code($code);
    jsonOut(['ok' => false, 'error' => $msg]);
}

// ── Wrappers PHP-nativo para lectura Excel (reemplaza Python) ────────────────

/**
 * Retorna info de hojas: [['name'=>'Sheet1','rows'=>int,'cols'=>int], ...]
 */
function xlsxGetSheets(string $filePath): array
{
    $reader = new PhpXlsxReader($filePath);
    $sheets = $reader->getSheets();
    $reader->close();
    return $sheets;
}

/**
 * Lee filas de una hoja. Retorna ['ok'=>true, 'rows'=>[[...], [...]]]
 */
function xlsxReadRows(string $filePath, string $sheetName, int $maxRows = 0): array
{
    $reader = new PhpXlsxReader($filePath);
    $rows   = $reader->readRows($sheetName, $maxRows);
    $reader->close();
    return ['ok' => true, 'rows' => $rows];
}

// ── Normalización de columnas ─────────────────────────────────
function normalizeCol(?string $col): string {
    if ($col === null || trim($col) === '') return '';
    $col = mb_strtolower(trim($col), 'UTF-8');
    $search  = ['á','é','í','ó','ú','ü','ñ','à','è','ì','ò','ù','â','ê','î','ô','û'];
    $replace = ['a','e','i','o','u','u','n','a','e','i','o','u','a','e','i','o','u'];
    $col = str_replace($search, $replace, $col);
    $col = preg_replace('/[^a-z0-9 ]/u', ' ', $col);
    return trim(preg_replace('/\s+/', ' ', $col));
}

function getFieldMap(): array {
    return [
        'id'                                    => 'lead_id',
        'lead id'                               => 'lead_id',
        'created time'                          => 'created_time',
        'ad id'                                 => 'ad_id',
        'ad name'                               => 'ad_name',
        'adset id'                              => 'adset_id',
        'adset name'                            => 'adset_name',
        'campaign id'                           => 'campaign_id',
        'campaign name'                         => 'campaign_name',
        'form id'                               => 'form_id',
        'form name'                             => 'form_name',
        'is organic'                            => 'is_organic',
        'platform'                              => 'platform',
        'full name'                             => 'nombre',
        'nombre completo'                       => 'nombre',
        'email'                                 => 'email',
        'correo electronico'                    => 'email',
        'telefono'                              => 'telefono',
        'phone'                                 => 'telefono',
        'phone number'                          => 'telefono',
        'cual es tu sexo'                       => 'sexo',
        'cual es su sexo'                       => 'sexo',
        'cual es tu edad'                       => 'rango_edad',
        'cual es su edad'                       => 'rango_edad',
        'cual es tu estrato'                    => 'estrato',
        'cual es tu estrato socioeconomico'     => 'estrato',
        'afiliacion politica'                   => 'afiliacion_politica',
        'cual es tu afiliacion politica'        => 'afiliacion_politica',
        'cual es su afiliacion politica'        => 'afiliacion_politica',
        'partido politico'                      => 'afiliacion_politica',
        'tendencia politica'                    => 'afiliacion_politica',
        'simpatia politica'                     => 'afiliacion_politica',
        'departamento'                          => 'geo_departamento',
        'cual es tu departamento'               => 'geo_departamento',
        'cual es su departamento'               => 'geo_departamento',
        'cual estu subregion'                   => 'subregion',
        'cual es tu subregion'                  => 'subregion',
        'cual es su subregion'                  => 'subregion',
        'municipio'                             => 'municipio',
        'cual es tu municipio'                  => 'municipio',
        'cual es su municipio'                  => 'municipio',
        'zona'                                  => 'zona',
        'cual es tu zona'                       => 'zona',
        'cual es su zona'                       => 'zona',
        'cual es tu municipio zona'             => 'zona',
        'cual es tu municipio comuna'           => 'comuna_localidad',
        'cual es su municipio comuna'           => 'comuna_localidad',
        'comuna'                                => 'comuna_localidad',
        'localidad'                             => 'comuna_localidad',
        'comuna localidad'                      => 'comuna_localidad',
        'cual es tu comuna'                     => 'comuna_localidad',
        'cual es su comuna'                     => 'comuna_localidad',
        'cual es tu barrio'                     => 'barrio',
        'cual es su barrio'                     => 'barrio',
        'nivel educativo'                       => 'nivel_educativo',
        'nivel de educacion'                    => 'nivel_educativo',
        'nivel de estudio'                      => 'nivel_educativo',
        'cual es tu nivel educativo'            => 'nivel_educativo',
        'cual es su nivel educativo'            => 'nivel_educativo',
        'education level'                       => 'nivel_educativo',
        'educacion'                             => 'nivel_educativo',
        'retailer item id'                      => '__skip',
        'lead status'                           => '__skip',
    ];
}

function detectColType(?string $col): string {
    if ($col === null || normalizeCol($col) === '') return '__skip';
    $norm = normalizeCol($col);
    $map  = getFieldMap();
    // 1. Coincidencia exacta — máxima prioridad
    if (isset($map[$norm])) return $map[$norm];
    // 2. Substring — solo para patrones largos (>2 chars).
    //    'id' (2 chars) causaría falsos positivos en 'actividad', 'calidad', etc.
    foreach ($map as $pattern => $field) {
        if (mb_strlen($pattern) <= 2) continue;
        if (str_contains($norm, $pattern)) return $field;
    }
    return '__eav';
}

function normalizeLeadId($val): ?string {
    if ($val === null) return null;
    $val = trim((string)$val);
    if ($val === '' || $val === '0') return null;
    $val = preg_replace('/^[a-z]+:/i', '', $val); // quitar prefijos "lead:"
    $val = preg_replace('/\.0+$/', '',   $val);   // quitar decimales ".000" de Excel
    $val = trim($val);
    // Acepta cualquier string no vacío — VARCHAR(64) soporta numérico, UUID, cédula, etc.
    return $val !== '' ? substr($val, 0, 64) : null;
}

function normalizeBool($val): int {
    if (is_bool($val)) return $val ? 1 : 0;
    return in_array(strtolower((string)$val), ['1','true','yes']) ? 1 : 0;
}

// ── Enriquecimiento geo desde adset_name / campaign_name ─────────

function normalizeGeoStr(string $s): string {
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'];
    return strtoupper(trim(str_replace(array_keys($map), array_values($map), $s)));
}

function normalizeSexo(?string $val): ?string {
    if (!$val) return null;
    $v = normalizeGeoStr($val);
    if (in_array($v, ['HOMBRE','HOMBRES','H','MALE','MASCULINO','MASC'])) return 'Hombre';
    if (in_array($v, ['MUJER','MUJERES','M','F','FEMALE','FEMENINO','FEM','W'])) return 'Mujer';
    return null;
}

function normalizeRangoEdad(?string $val): ?string {
    if (!$val) return null;
    $s = strtolower(trim($val));

    // Ya es un grupo válido
    if (in_array($s, ['18a24','25a34','35a44','45a65+'])) return $s;

    // Rango de edad explícito (≥2 dígitos para el inicio)
    // Patrón: "18a24", "25-34", "40a65+", "45+", "35A44"
    if (preg_match('/(?<!\d)(\d{2,3})\s*(?:a|\-)\s*\d{2,3}\+?(?!\d)/i', $s, $m) ||
        preg_match('/(?<!\d)(\d{2,3})\s*\+/i', $s, $m)) {
        $lo = (int)$m[1];
        if ($lo >= 15 && $lo <= 75) {
            if ($lo < 25) return '18a24';
            if ($lo < 35) return '25a34';
            if ($lo < 45) return '35a44';
            return '45a65+';
        }
    }

    // Fecha de nacimiento → calcular edad y asignar grupo
    // Formatos: "1989-06-06 00:00:00", "1989-06-06", "04/16/1989", "1989"
    $birthYear = null;
    if      (preg_match('/^(\d{4})-\d{2}-\d{2}/', $s, $m))              $birthYear = (int)$m[1]; // YYYY-MM-DD
    elseif  (preg_match('/\b\d{2}\/\d{2}\/(\d{4})\b/', $s, $m))         $birthYear = (int)$m[1]; // MM/DD/YYYY o DD/MM/YYYY
    elseif  (preg_match('/^(\d{4})$/', $s, $m))                          $birthYear = (int)$m[1]; // solo año
    elseif  (preg_match('/\b((?:19|20)\d{2})\b/', $s, $m))              $birthYear = (int)$m[1]; // año embebido

    if ($birthYear && $birthYear >= 1920 && $birthYear <= (int)date('Y') - 10) {
        $age = (int)date('Y') - $birthYear;
        if ($age < 25) return '18a24';
        if ($age < 35) return '25a34';
        if ($age < 45) return '35a44';
        return '45a65+';
    }

    return null;
}

/**
 * Limpia y valida un número de teléfono colombiano.
 * Maneja: "p:+573006310718", "+57+573102179144", "+573002705511"
 * Retorna formato 57XXXXXXXXXX (12 dígitos) o 10 dígitos, o null si inválido.
 */
function normalizePhone(?string $val): ?string {
    if ($val === null || trim($val) === '') return null;
    $s = trim($val);

    // 1. Quitar prefijos alfabéticos: "p:", "tel:", "mob:", etc.
    $s = preg_replace('/^[a-zA-Z]{1,4}\s*[:]\s*/', '', $s);

    // 2. Quitar espacios, paréntesis, guiones, puntos
    $s = preg_replace('/[\s()\-\.]+/', '', $s);

    // 3. Quitar todos los '+' y recomponer solo con dígitos
    $digits = preg_replace('/[^\d]/', '', $s);

    if (!$digits) return null;

    // 4. Doble código de país: 5757XXXXXXXXXX (14 dígitos) → quitar los primeros 57
    if (strlen($digits) === 14 && str_starts_with($digits, '5757')) {
        $digits = substr($digits, 2); // → 573XXXXXXXXXX (12)
    }
    // También: 57573XXXXXXXXXX (15 dígitos con error de duplicación)
    if (strlen($digits) > 12 && str_starts_with($digits, '5757')) {
        $digits = substr($digits, 2);
    }

    // 5. Validar longitud y formato colombiano
    $len = strlen($digits);

    // 12 dígitos: 57 + 10 → válido
    if ($len === 12 && str_starts_with($digits, '57')) return $digits;

    // 10 dígitos: móvil (3XXXXXXXXX) o fijo (60XXXXXXXX)
    if ($len === 10 && (str_starts_with($digits, '3') || str_starts_with($digits, '6'))) return $digits;

    // 7 dígitos: fijo local
    if ($len === 7) return $digits;

    // 11 dígitos comenzando con 573 (a veces vienen así): quitar el primer 5
    if ($len === 11 && str_starts_with($digits, '573')) return $digits;

    // Si tiene más de 12 y no se resolvió → intentar extraer últimos 10 (número local)
    if ($len > 12) {
        $last10 = substr($digits, -10);
        if (str_starts_with($last10, '3') || str_starts_with($last10, '6')) return '57' . $last10;
    }

    // Si tiene entre 8-9 dígitos → posiblemente fijo incompleto, devolver tal cual
    if ($len >= 8 && $len <= 9) return $digits;

    return null; // no reconocido
}

function lookupGeoByName(string $name, \PDO $db): ?array {
    if (mb_strlen(trim($name)) < 3) return null;
    $norm = normalizeGeoStr($name);
    $st = $db->prepare(
        "SELECT m.nombre AS nom_mpio, d.nombre AS nom_dpto,
                s.nombre AS nom_subregion, NULL AS nom_zona
         FROM c98_geo.dim_municipio m
         LEFT JOIN c98_geo.dim_departamento d ON d.id = m.id_departamento
         LEFT JOIN c98_geo.dim_subregion    s ON s.id = m.id_subregion
         WHERE " . _nsql('m.nombre') . " = ? OR " . _nsql('m.abreviatura') . " = ?
         LIMIT 1"
    );
    $st->execute([$norm, $norm]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function lookupComunaByName(string $nomComuna, ?string $nomMpio, \PDO $db): ?array {
    if (mb_strlen(trim($nomComuna)) < 2) return null;
    $normC = '%' . normalizeGeoStr($nomComuna) . '%';
    if ($nomMpio) {
        $normM = '%' . normalizeGeoStr($nomMpio) . '%';
        $st = $db->prepare(
            "SELECT c.nombre AS nom_comuna, m.nombre AS nom_mpio, d.nombre AS nom_dpto, s.nombre AS nom_subregion
             FROM c98_geo.dim_comuna c
             JOIN c98_geo.dim_zona z ON z.id = c.id_zona
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
             LEFT JOIN c98_geo.dim_departamento d ON d.id = m.id_departamento
             LEFT JOIN c98_geo.dim_subregion    s ON s.id = m.id_subregion
             WHERE " . _nsql('c.nombre') . " LIKE ?
               AND (" . _nsql('m.nombre') . " LIKE ? OR " . _nsql('m.abreviatura') . " LIKE ?)
             LIMIT 1"
        );
        $st->execute([$normC, $normM, $normM]);
    } else {
        $st = $db->prepare(
            "SELECT c.nombre AS nom_comuna, m.nombre AS nom_mpio, d.nombre AS nom_dpto, s.nombre AS nom_subregion
             FROM c98_geo.dim_comuna c
             JOIN c98_geo.dim_zona z ON z.id = c.id_zona
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
             LEFT JOIN c98_geo.dim_departamento d ON d.id = m.id_departamento
             LEFT JOIN c98_geo.dim_subregion    s ON s.id = m.id_subregion
             WHERE " . _nsql('c.nombre') . " LIKE ?
             LIMIT 1"
        );
        $st->execute([$normC]);
    }
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function lookupBarrioByNameScoped(string $nomBarrio, string $nomMpio, \PDO $db): ?array {
    if (mb_strlen(trim($nomBarrio)) < 3) return null;
    $normB = '%' . normalizeGeoStr($nomBarrio) . '%';
    $normM = '%' . normalizeGeoStr($nomMpio) . '%';
    $st = $db->prepare(
        "SELECT b.nombre AS nom_barrio, c.nombre AS nom_comuna
         FROM c98_geo.dim_barrio b
         JOIN c98_geo.dim_comuna c ON c.id = b.id_comuna
         JOIN c98_geo.dim_zona   z ON z.id = c.id_zona
         JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
         WHERE " . _nsql('b.nombre') . " LIKE ?
           AND (" . _nsql('m.nombre') . " LIKE ? OR " . _nsql('m.abreviatura') . " LIKE ?)
         LIMIT 1"
    );
    $st->execute([$normB, $normM, $normM]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function lookupSubregionByName(string $name, \PDO $db): ?array {
    if (mb_strlen(trim($name)) < 3) return null;
    $norm = normalizeGeoStr($name);
    $st = $db->prepare(
        "SELECT s.nombre AS nom_subregion, d.nombre AS nom_dpto
         FROM c98_geo.dim_subregion s
         LEFT JOIN c98_geo.dim_departamento d ON d.id = s.id_departamento
         WHERE " . _nsql('s.nombre') . " = ?
         LIMIT 1"
    );
    $st->execute([$norm]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function lookupBarrioByName(string $name, \PDO $db): ?array {
    if (mb_strlen(trim($name)) < 3) return null;
    $norm = '%' . normalizeGeoStr($name) . '%';
    $st = $db->prepare(
        "SELECT m.nombre AS nom_mpio, d.nombre AS nom_dpto, s.nombre AS nom_subregion,
                z.nombre AS nom_zona,  c.nombre AS nom_comuna
         FROM c98_geo.dim_barrio b
         JOIN c98_geo.dim_comuna c ON c.id = b.id_comuna
         JOIN c98_geo.dim_zona   z ON z.id = c.id_zona
         JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
         LEFT JOIN c98_geo.dim_departamento d ON d.id = m.id_departamento
         LEFT JOIN c98_geo.dim_subregion    s ON s.id = m.id_subregion
         WHERE " . _nsql('b.nombre') . " LIKE ?
         LIMIT 1"
    );
    $st->execute([$norm]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function lookupDepartamentoByName(string $name, \PDO $db): ?array {
    if (mb_strlen(trim($name)) < 3) return null;
    $norm = normalizeGeoStr($name);
    $st = $db->prepare(
        "SELECT id AS id_dpto, nombre AS nom_dpto
         FROM c98_geo.dim_departamento
         WHERE " . _nsql('nombre') . " = ?
         LIMIT 1"
    );
    $st->execute([$norm]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row) return $row;
    // Fallback LIKE — nombres compuestos con separador distinto al de la fuente
    $st2 = $db->prepare(
        "SELECT id AS id_dpto, nombre AS nom_dpto
         FROM c98_geo.dim_departamento
         WHERE " . _nsql('nombre') . " LIKE ?
         LIMIT 1"
    );
    $st2->execute(['%' . $norm . '%']);
    return $st2->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** Macro SQL: normaliza tildes/mayúsculas en una expresión SQL para comparación */
function _nsql(string $col): string {
    return "UPPER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        $col,'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),'ñ','n')))";
}

/**
 * Resuelve el id_geo más granular disponible para un lead.
 * Usa c98_geo (BD externa) — retorna código DANE VARCHAR:
 *   P1: dim_barrio.id  (15 chars)  "05001Z01C01B001"
 *   P2: dim_comuna.id  (11 chars)  "05001Z01C01"
 *   P2b:dim_zona.id    ( 8 chars)  "05001Z01"
 *   P3: dim_municipio.id (5 chars) "05001"
 * Retorna null si no hay match.
 */
function resolveGeoId(array $lead, \PDO $db): ?string {
    $barrio = trim((string)($lead['barrio']           ?? ''));
    $zona   = trim((string)($lead['zona']             ?? ''));
    $comuna = trim((string)($lead['comuna_localidad'] ?? ''));
    $mpio   = trim((string)($lead['municipio']        ?? ''));

    // Normalizar Bogotá localidades
    if ($mpio !== '' && stripos($mpio, 'LOCALIDAD') !== false) {
        $mpio = 'Bogota';
    }

    $mNorm = normalizeGeoStr($mpio);

    // P1 – Barrio: busca barrio en el municipio correcto
    if ($barrio !== '' && $mpio !== '') {
        $st = $db->prepare(
            "SELECT b.id FROM c98_geo.dim_barrio b
             JOIN c98_geo.dim_comuna  c ON c.id = b.id_comuna
             JOIN c98_geo.dim_zona    z ON z.id = c.id_zona
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
             WHERE "._nsql('b.nombre')." = ?
               AND ("._nsql('m.nombre')." = ? OR "._nsql('m.abreviatura')." = ?)
             LIMIT 1"
        );
        $st->execute([normalizeGeoStr($barrio), $mNorm, $mNorm]);
        $id = $st->fetchColumn();
        if ($id !== false) return (string)$id;
    }

    // P2 – Comuna
    if ($comuna !== '' && $mpio !== '') {
        $st = $db->prepare(
            "SELECT c.id FROM c98_geo.dim_comuna c
             JOIN c98_geo.dim_zona      z ON z.id = c.id_zona
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
             WHERE "._nsql('c.nombre')." = ?
               AND ("._nsql('m.nombre')." = ? OR "._nsql('m.abreviatura')." = ?)
             LIMIT 1"
        );
        $st->execute([normalizeGeoStr($comuna), $mNorm, $mNorm]);
        $id = $st->fetchColumn();
        if ($id !== false) return (string)$id;
    }

    // P2b – Zona
    if ($zona !== '' && $mpio !== '') {
        $st = $db->prepare(
            "SELECT z.id FROM c98_geo.dim_zona z
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
             WHERE "._nsql('z.nombre')." = ?
               AND ("._nsql('m.nombre')." = ? OR "._nsql('m.abreviatura')." = ?)
             LIMIT 1"
        );
        $st->execute([normalizeGeoStr($zona), $mNorm, $mNorm]);
        $id = $st->fetchColumn();
        if ($id !== false) return (string)$id;
    }

    // P3 – Municipio fallback
    if ($mpio !== '') {
        $st = $db->prepare(
            "SELECT id FROM c98_geo.dim_municipio
             WHERE "._nsql('nombre')." = ? OR "._nsql('abreviatura')." = ?
             LIMIT 1"
        );
        $st->execute([$mNorm, $mNorm]);
        $id = $st->fetchColumn();
        if ($id !== false) return (string)$id;
    }

    // P4 – Departamento fallback (targeting regional amplio, sin ciudad conocida —
    // ej. campañas "(SC)" = sin ciudad). Único caso donde id_geo queda en 2 chars.
    $dptoTxt = trim((string)($lead['geo_departamento'] ?? ''));
    if ($dptoTxt !== '') {
        $dpto = lookupDepartamentoByName($dptoTxt, $db);
        if ($dpto) return (string)$dpto['id_dpto'];
    }

    return null;
}

// Alias explícitos de municipios colombianos — resuelve variaciones de tildes,
// nombres comunes vs. oficiales y typos frecuentes.
// Clave = normalizeGeoStr(variación) → datos canónicos [mpio, dpto, sub].
const GEO_MPIO_ALIASES = [
    'MEDELLIN'                   => ['mpio'=>'Medellín',           'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'CALI'                       => ['mpio'=>'Santiago de Cali',   'dpto'=>'Valle del Cauca',     'sub'=>null],
    'SANTIAGO DE CALI'           => ['mpio'=>'Santiago de Cali',   'dpto'=>'Valle del Cauca',     'sub'=>null],
    'CUCUTA'                     => ['mpio'=>'Cúcuta',             'dpto'=>'Norte de Santander',  'sub'=>null],
    'SAN JOSE DE CUCUTA'         => ['mpio'=>'Cúcuta',             'dpto'=>'Norte de Santander',  'sub'=>null],
    'BOGOTA'                     => ['mpio'=>'Bogotá D.C.',        'dpto'=>'Bogotá D.C.',         'sub'=>'Bogotá D.C.'],
    'BOGOTA DC'                  => ['mpio'=>'Bogotá D.C.',        'dpto'=>'Bogotá D.C.',         'sub'=>'Bogotá D.C.'],
    'BOGOTA D.C.'                => ['mpio'=>'Bogotá D.C.',        'dpto'=>'Bogotá D.C.',         'sub'=>'Bogotá D.C.'],
    'BARRANQUILLA'               => ['mpio'=>'Barranquilla',       'dpto'=>'Atlántico',           'sub'=>null],
    'CARTAGENA'                  => ['mpio'=>'Cartagena de Indias','dpto'=>'Bolívar',             'sub'=>null],
    'CARTAGENA DE INDIAS'        => ['mpio'=>'Cartagena de Indias','dpto'=>'Bolívar',             'sub'=>null],
    'IBAGUE'                     => ['mpio'=>'Ibagué',             'dpto'=>'Tolima',              'sub'=>null],
    'MANIZALES'                  => ['mpio'=>'Manizales',          'dpto'=>'Caldas',              'sub'=>null],
    'MANIZALEZ'                  => ['mpio'=>'Manizales',          'dpto'=>'Caldas',              'sub'=>null],
    'PEREIRA'                    => ['mpio'=>'Pereira',            'dpto'=>'Risaralda',           'sub'=>null],
    'BUCARAMANGA'                => ['mpio'=>'Bucaramanga',        'dpto'=>'Santander',           'sub'=>null],
    'POPAYAN'                    => ['mpio'=>'Popayán',            'dpto'=>'Cauca',               'sub'=>null],
    'NEIVA'                      => ['mpio'=>'Neiva',              'dpto'=>'Huila',               'sub'=>null],
    'RIOHACHA'                   => ['mpio'=>'Riohacha',           'dpto'=>'La Guajira',          'sub'=>null],
    'RIOACHA'                    => ['mpio'=>'Riohacha',           'dpto'=>'La Guajira',          'sub'=>null],
    'SANTA MARTA'                => ['mpio'=>'Santa Marta',        'dpto'=>'Magdalena',           'sub'=>null],
    'VALLEDUPAR'                 => ['mpio'=>'Valledupar',         'dpto'=>'Cesar',               'sub'=>null],
    'MONTERIA'                   => ['mpio'=>'Montería',           'dpto'=>'Córdoba',             'sub'=>null],
    'SINCELEJO'                  => ['mpio'=>'Sincelejo',          'dpto'=>'Sucre',               'sub'=>null],
    'VILLAVICENCIO'              => ['mpio'=>'Villavicencio',      'dpto'=>'Meta',                'sub'=>null],
    'ARMENIA'                    => ['mpio'=>'Armenia',            'dpto'=>'Quindío',             'sub'=>null],
    'PASTO'                      => ['mpio'=>'Pasto',              'dpto'=>'Nariño',              'sub'=>null],
    'TUNJA'                      => ['mpio'=>'Tunja',              'dpto'=>'Boyacá',              'sub'=>null],
    'FLORENCIA'                  => ['mpio'=>'Florencia',          'dpto'=>'Caquetá',             'sub'=>null],
    'YOPAL'                      => ['mpio'=>'Yopal',              'dpto'=>'Casanare',            'sub'=>null],
    'ARAUCA'                     => ['mpio'=>'Arauca',             'dpto'=>'Arauca',              'sub'=>null],
    'SAN ANDRES'                 => ['mpio'=>'San Andrés',         'dpto'=>'Archipiélago de San Andrés, Providencia y Santa Catalina','sub'=>null],
    'SAN ANDRES ISLAS'           => ['mpio'=>'San Andrés',         'dpto'=>'Archipiélago de San Andrés, Providencia y Santa Catalina','sub'=>null],
    'LETICIA'                    => ['mpio'=>'Leticia',            'dpto'=>'Amazonas',            'sub'=>null],
    'MOCOA'                      => ['mpio'=>'Mocoa',              'dpto'=>'Putumayo',            'sub'=>null],
    'BELLO'                      => ['mpio'=>'Bello',              'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'ENVIGADO'                   => ['mpio'=>'Envigado',           'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'ITAGUI'                     => ['mpio'=>'Itagüí',             'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'SABANETA'                   => ['mpio'=>'Sabaneta',           'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
    'LA ESTRELLA'                => ['mpio'=>'La Estrella',        'dpto'=>'Antioquia',           'sub'=>'Valle de Aburrá'],
];

// Zonas reconocidas en nombres de campaña/adset/ad
const GEO_ZONAS = ['NORTE','SUR','ORIENTE','OCCIDENTE','OCCIDENTAL','ORIENTAL',
                   'CENTRO','NOROCCIDENTE','NORORIENTE','SUROCCIDENTE','SURORIENTE',
                   'CENTRORIENTE','CENTROCCIDENTE','LADERA','PACIFICO'];

function parseAdsetForGeo(string $s, \PDO $db): array {
    $result = [];

    // 1. Ciudad entre paréntesis: REGION-DPTO(CIUDAD)_SEXO  →  SC = "sin ciudad"
    if (preg_match('/\(([^)]+)\)/', $s, $m)) {
        $city = trim($m[1]);
        if (normalizeGeoStr($city) !== 'SC') {
            $geo = lookupGeoByName($city, $db);
            if ($geo) $result['_geo'] = $geo;
        }
    }

    // 1b. Departamento explícito en el patrón REGION-DEPARTAMENTO(...): el segmento
    //     entre el primer "-" y el primer "(". Cubre targeting regional amplio sin
    //     ciudad específica (tag "(SC)"), donde el paso 1 no resuelve nada porque
    //     lookupGeoByName solo matchea contra dim_municipio, no dim_departamento.
    if (empty($result['_geo']['nom_dpto'] ?? null)) {
        if (preg_match('/^[^\-]+-([^(]+)\(/', $s, $md)) {
            $dptoCandidate = trim(str_replace('_', ' ', $md[1]));
            if (mb_strlen($dptoCandidate) >= 3) {
                $dpto = lookupDepartamentoByName($dptoCandidate, $db);
                if ($dpto) {
                    $result['_geo'] = ($result['_geo'] ?? []) + [
                        'nom_mpio' => null, 'nom_subregion' => null, 'nom_zona' => null,
                    ];
                    $result['_geo']['nom_dpto'] = $dpto['nom_dpto'];
                }
            }
        }
    }

    // 2. Edad con patrón estricto (≥2 dígitos, inicio ≥15): buscar en el string completo
    //    antes de splitear, para manejar "OCT3-40a65+" correctamente
    if (preg_match('/(?<!\d)(\d{2,3})\s*(?:a|\-)\s*\d{2,3}\+?(?!\d)/i', $s, $m)) {
        $ag = normalizeRangoEdad($m[0]); // usar el match completo
        if ($ag) $result['rango_edad'] = $ag;
    } elseif (preg_match('/(?<!\d)(\d{2,3})\+/i', $s, $m)) {
        $ag = normalizeRangoEdad($m[0]);
        if ($ag) $result['rango_edad'] = $ag;
    }

    // 3. Segmentos: split en _, -, /, espacios
    $parts = preg_split('/[_\-\/\s]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $part) {
        // Quitar paréntesis residuales
        $part = trim(preg_replace('/\([^)]*\)/', '', $part));
        if (!$part) continue;
        // Saltar IDs numéricos largos (campaign IDs, form IDs)
        if (preg_match('/^\d{5,}$/', $part)) continue;

        // Sexo
        if (!isset($result['sexo'])) {
            $sx = normalizeSexo($part);
            if ($sx) { $result['sexo'] = $sx; continue; }
        }

        // Zona (antes de geo para evitar falsos positivos)
        if (!isset($result['zona'])) {
            $pn = normalizeGeoStr($part);
            if (in_array($pn, GEO_ZONAS)) {
                $result['zona'] = mb_convert_case(mb_strtolower($pn), MB_CASE_TITLE, 'UTF-8');
                continue;
            }
        }

        // Municipio (saltar si ya tenemos geo o si el segmento tiene muy pocas letras útiles)
        if (!isset($result['_geo']) && mb_strlen($part) >= 4 && !preg_match('/^\d/', $part)) {
            $geo = lookupGeoByName($part, $db);
            if ($geo) { $result['_geo'] = $geo; }
        }
    }

    // 4. Comuna/Barrio — solo si ya se conoce el municipio (evita falsos positivos:
    //    nombres de barrio se repiten entre ciudades distintas).
    $nomMpio = $result['_geo']['nom_mpio'] ?? null;
    if ($nomMpio) {
        foreach ($parts as $part) {
            $part = trim(preg_replace('/\([^)]*\)/', '', $part));
            if (mb_strlen($part) < 3) continue;
            if (!isset($result['comuna_localidad'])) {
                $c = lookupComunaByName($part, $nomMpio, $db);
                if ($c) { $result['comuna_localidad'] = $c['nom_comuna']; continue; }
            }
            if (!isset($result['barrio'])) {
                $b = lookupBarrioByNameScoped($part, $nomMpio, $db);
                if ($b) { $result['barrio'] = $b['nom_barrio']; }
            }
        }
    }
    return $result;
}

/**
 * Reconcilia la jerarquía geográfica a partir de campos YA presentes en el lead
 * (mapeados directamente desde columnas del Excel) — ej. si vino "barrio" pero no
 * "municipio", lo deriva. NO adivina desde adset_name/campaign_name/ad_name — eso
 * es autoParseLeadGeo(), que solo corre si el usuario lo activa explícitamente.
 * Solo rellena campos vacíos (no sobreescribe). Devuelve true si modificó algo.
 */
function reconcileLeadGeo(array &$lead, \PDO $db): bool {
    $enriched = false;

    // 0. Detectar municipio mal clasificado en campo subregion
    //    Orden: alias explícito → dim_geo lookup
    if (!empty($lead['subregion'])) {
        $srNorm  = normalizeGeoStr($lead['subregion']);
        $aliasM  = GEO_MPIO_ALIASES[$srNorm] ?? null;
        $isMpio  = null;

        if ($aliasM) {
            // Alias explícito — más fiable, sin dependencia de charset/dim_geo
            $mpioActual = normalizeGeoStr((string)($lead['municipio'] ?? ''));
            $mpioMatch  = normalizeGeoStr($aliasM['mpio']);
            if (empty($lead['municipio']) || $mpioActual === $mpioMatch) {
                $lead['municipio']        = $lead['municipio']        ?: $aliasM['mpio'];
                $lead['geo_departamento'] = $lead['geo_departamento'] ?: $aliasM['dpto'];
                $lead['subregion']        = $aliasM['sub'] ?? null;
                $enriched = true;
            }
        } else {
            $isMpio = lookupGeoByName($lead['subregion'], $db);
            if ($isMpio && $isMpio['nom_mpio']) {
                $mpioActual = strtoupper(trim((string)($lead['municipio'] ?? '')));
                $mpioMatch  = strtoupper(trim($isMpio['nom_mpio']));
                if (empty($lead['municipio']) || $mpioActual === $mpioMatch) {
                    $lead['municipio']        = $lead['municipio']        ?: $isMpio['nom_mpio'];
                    $lead['geo_departamento'] = $lead['geo_departamento'] ?: $isMpio['nom_dpto'];
                    $lead['subregion']        = $isMpio['nom_subregion'] ?: null;
                    $lead['zona']             = $lead['zona']             ?: $isMpio['nom_zona'];
                    $enriched = true;
                }
            }
        }
    }

    // 1. Reverse lookup barrio → cadena geo completa
    if (!empty($lead['barrio']) && empty($lead['municipio'])) {
        $geoB = lookupBarrioByName($lead['barrio'], $db);
        if ($geoB) {
            $lead['municipio']        = $lead['municipio']        ?: $geoB['nom_mpio'];
            $lead['subregion']        = $lead['subregion']        ?: $geoB['nom_subregion'];
            $lead['geo_departamento'] = $lead['geo_departamento'] ?: $geoB['nom_dpto'];
            $lead['zona']             = $lead['zona']             ?: $geoB['nom_zona'];
            $lead['comuna_localidad'] = $lead['comuna_localidad'] ?: $geoB['nom_comuna'];
            $enriched = true;
        }
    }

    // 2. Reverse lookup comuna_localidad → municipio si está vacío
    if (!empty($lead['comuna_localidad']) && empty($lead['municipio'])) {
        $geoC = lookupComunaByName($lead['comuna_localidad'], null, $db);
        if ($geoC) {
            $lead['municipio']        = $lead['municipio']        ?: $geoC['nom_mpio'];
            $lead['subregion']        = $lead['subregion']        ?: $geoC['nom_subregion'];
            $lead['geo_departamento'] = $lead['geo_departamento'] ?: $geoC['nom_dpto'];
            $enriched = true;
        }
    }

    // 2b. Rellenar geo_departamento (y subregion) desde municipio → alias → dim_geo
    //     Cubre el caso: municipio = "Medellín"/"Medellin", geo_departamento = NULL
    if (!empty($lead['municipio']) && (empty($lead['geo_departamento']) || empty($lead['subregion']))) {
        $mnNorm  = normalizeGeoStr($lead['municipio']);
        $aliasM2 = GEO_MPIO_ALIASES[$mnNorm] ?? null;
        if ($aliasM2) {
            // Normalizar nombre al canónico (ej. "Medellin" → "Medellín")
            if ($lead['municipio'] !== $aliasM2['mpio'])
                { $lead['municipio'] = $aliasM2['mpio']; $enriched = true; }
            if (empty($lead['geo_departamento']) && !empty($aliasM2['dpto']))
                { $lead['geo_departamento'] = $aliasM2['dpto']; $enriched = true; }
            if (empty($lead['subregion']) && !empty($aliasM2['sub']))
                { $lead['subregion'] = $aliasM2['sub']; $enriched = true; }
        } else {
            $geoMpio = lookupGeoByName($lead['municipio'], $db);
            if ($geoMpio) {
                if (empty($lead['geo_departamento']) && !empty($geoMpio['nom_dpto']))
                    { $lead['geo_departamento'] = $geoMpio['nom_dpto']; $enriched = true; }
                if (empty($lead['subregion']) && !empty($geoMpio['nom_subregion']))
                    { $lead['subregion'] = $geoMpio['nom_subregion']; $enriched = true; }
            }
        }
    }

    // 2c. Rellenar geo_departamento desde subregion conocida → dim_geo
    //     Cubre el caso: subregion = "Area Metropolitana", geo_departamento = NULL
    if (!empty($lead['subregion']) && empty($lead['geo_departamento'])) {
        $geoSub = lookupSubregionByName($lead['subregion'], $db);
        if ($geoSub && !empty($geoSub['nom_dpto'])) {
            $lead['geo_departamento'] = $geoSub['nom_dpto']; $enriched = true;
        }
    }

    return $enriched;
}

/**
 * Parsea UNA columna de texto (adset_name/campaign_name/ad_name/form_name, la que
 * elija el usuario) para rellenar los niveles geográficos que el usuario haya
 * activado explícitamente en el wizard. Nunca corre si $levels está vacío — es
 * opt-in, no hay adivinanza silenciosa. Solo rellena campos vacíos.
 */
function autoParseLeadGeo(array &$lead, \PDO $db, array $levels, string $source): bool {
    if (empty($levels)) return false;
    $srcVal = trim((string)($lead[$source] ?? ''));
    if ($srcVal === '') return false;

    $enriched = false;
    $parsed = parseAdsetForGeo($srcVal, $db);

    if (isset($parsed['_geo'])) {
        $geo = $parsed['_geo'];
        if (in_array('municipio', $levels) && empty($lead['municipio']) && !empty($geo['nom_mpio'])) {
            $lead['municipio'] = $geo['nom_mpio']; $enriched = true;
        }
        if (in_array('subregion', $levels) && empty($lead['subregion']) && !empty($geo['nom_subregion'])) {
            $lead['subregion'] = $geo['nom_subregion']; $enriched = true;
        }
        if (in_array('geo_departamento', $levels) && empty($lead['geo_departamento']) && !empty($geo['nom_dpto'])) {
            $lead['geo_departamento'] = $geo['nom_dpto']; $enriched = true;
        }
    }
    if (in_array('zona', $levels) && empty($lead['zona']) && isset($parsed['zona'])) {
        $lead['zona'] = $parsed['zona']; $enriched = true;
    }
    if (in_array('comuna_localidad', $levels) && empty($lead['comuna_localidad']) && isset($parsed['comuna_localidad'])) {
        $lead['comuna_localidad'] = $parsed['comuna_localidad']; $enriched = true;
    }
    if (in_array('barrio', $levels) && empty($lead['barrio']) && isset($parsed['barrio'])) {
        $lead['barrio'] = $parsed['barrio']; $enriched = true;
    }
    // Sexo/edad no son "niveles geo" pero vienen del mismo parseo — se aplican gratis
    // cuando el usuario activó al menos un nivel (ya aceptó que se lea esta columna).
    if (empty($lead['sexo']) && isset($parsed['sexo'])) { $lead['sexo'] = $parsed['sexo']; $enriched = true; }
    if (empty($lead['rango_edad']) && isset($parsed['rango_edad'])) { $lead['rango_edad'] = $parsed['rango_edad']; $enriched = true; }

    return $enriched;
}

function handleUpload(): string {
    if (empty($_FILES['file']['tmp_name'])) errOut('No se recibió archivo.');
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) errOut('Error de upload: código ' . $_FILES['file']['error']);
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx','xls','ods'])) errOut('Formato no soportado. Use .xlsx, .xls u .ods');
    $tmpName = 'lead_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $tmpPath = TMP_DIR . $tmpName;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath)) errOut('No se pudo guardar el archivo temporal.', 500);
    return $tmpPath;
}

// ── Router ───────────────────────────────────────────────────
// Guard: permite `require`/`include` este archivo desde scripts CLI de
// mantenimiento (ej. reparaciones puntuales de datos) solo para reusar sus
// funciones, sin disparar el router HTTP ni su fallback "Acción no reconocida".
if (php_sapi_name() === 'cli' && !defined('LEADS_IMPORTER_RUN_ROUTER')) {
    return;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {

    // ── CHECK ────────────────────────────────────────────────
    if ($action === 'check') {
        // Verificar extensiones PHP necesarias
        $hasZip = class_exists('ZipArchive');
        $hasXml = function_exists('simplexml_load_string');

        // Verificar acceso a c98_geo
        $c98Status = 'skipped';
        try {
            $db = getDB();
            $cnt = (int)$db->query("SELECT COUNT(*) FROM c98_geo.dim_municipio")->fetchColumn();
            $c98Status = "ready:$cnt municipios";
        } catch (\Exception $e) {
            $c98Status = 'error:' . $e->getMessage();
        }

        jsonOut([
            'ok'          => true,
            'python'      => true,      // siempre OK — ya no se usa Python
            'openpyxl'    => 'php-native',
            'xlrd'        => false,
            'php_version' => PHP_VERSION,
            'php_zip'     => $hasZip,
            'php_xml'     => $hasXml,
            'c98_geo'     => $c98Status,
        ]);
    }

    // ── STATS ─────────────────────────────────────────────────
    if ($action === 'stats') {
        $db      = getDB();
        $row     = $db->query("SELECT
                        COALESCE(SUM(filas_ins), 0) AS total_nuevos,
                        COALESCE(SUM(filas_dup), 0) AS total_dup,
                        COALESCE(SUM(filas_err), 0) AS total_err,
                        COUNT(*)                    AS total_imps
                      FROM import_log")->fetch(PDO::FETCH_ASSOC);
        $ultiImp = $db->query("SELECT archivo, importado_en, filas_ins
                               FROM import_log ORDER BY id DESC LIMIT 1")
                      ->fetch(PDO::FETCH_ASSOC);
        jsonOut([
            'ok'          => true,
            'total_nuevos'=> (int)($row['total_nuevos'] ?? 0),
            'total_dup'   => (int)($row['total_dup']    ?? 0),
            'total_err'   => (int)($row['total_err']    ?? 0),
            'total_imps'  => (int)($row['total_imps']   ?? 0),
            'ultima_imp'  => $ultiImp ?: null,
        ]);
    }

    // ── ANALYTICS ────────────────────────────────────────────
    // Analítica completa de leads: cobertura, distribuciones, jerarquía geo
    if ($action === 'analytics') {
        $db = getDB();

        // Filtros opcionales
        $importId  = isset($_GET['import_id'])  ? (int)$_GET['import_id']  : null;
        $where     = $importId ? "WHERE import_id = $importId" : "WHERE 1=1";
        $whereAnd  = $importId ? "AND import_id = $importId"  : "";
        // Variantes con alias de tabla l (para queries con JOIN dim_geo)
        $whereL    = $importId ? "WHERE l.import_id = $importId" : "WHERE 1=1";
        $whereAndL = $importId ? "AND l.import_id = $importId"   : "";

        try {
            // 1. KPIs globales — usa LEFT JOIN c98_geo via LEFT(id_geo,N)
            // id_geo es VARCHAR(15) con código DANE jerárquico:
            //   5 chars = municipio, 8 = zona, 11 = comuna, 15 = barrio
            $kpi = $db->query("
                SELECT
                    COUNT(*) AS total,
                    COUNT(l.nombre)               AS c_nombre,
                    COUNT(l.email)                AS c_email,
                    COUNT(l.telefono)             AS c_telefono,
                    COUNT(l.sexo)                 AS c_sexo,
                    COUNT(l.rango_edad)           AS c_rango_edad,
                    COUNT(l.estrato)              AS c_estrato,
                    COUNT(l.afiliacion_politica)  AS c_afiliacion_politica,
                    COUNT(l.nivel_educativo)      AS c_nivel_educativo,
                    COUNT(l.id_geo)               AS c_id_geo,
                    COUNT(dpto.id)                AS c_geo_departamento,
                    COUNT(m.id)                   AS c_municipio,
                    COUNT(z.id)                   AS c_zona,
                    COUNT(c.id)                   AS c_comuna_localidad,
                    COUNT(b.id)                   AS c_barrio,
                    COUNT(sr.id)                  AS c_subregion,
                    COUNT(l.platform)             AS c_platform,
                    COUNT(NULLIF(l.is_organic,0)) AS c_organic,
                    MIN(l.created_time)           AS primera_fecha,
                    MAX(l.created_time)           AS ultima_fecha
                FROM import_log_leads l
                LEFT JOIN c98_geo.dim_municipio   m    ON m.id   = LEFT(l.id_geo, 5)    AND l.id_geo IS NOT NULL
                LEFT JOIN c98_geo.dim_departamento dpto ON dpto.id = LEFT(l.id_geo, 2)   AND l.id_geo IS NOT NULL
                LEFT JOIN c98_geo.dim_subregion   sr   ON sr.id  = m.id_subregion
                LEFT JOIN c98_geo.dim_zona        z    ON z.id   = LEFT(l.id_geo, 8)    AND CHAR_LENGTH(l.id_geo) >= 8
                LEFT JOIN c98_geo.dim_comuna      c    ON c.id   = LEFT(l.id_geo, 11)   AND CHAR_LENGTH(l.id_geo) >= 11
                LEFT JOIN c98_geo.dim_barrio      b    ON b.id   = l.id_geo             AND CHAR_LENGTH(l.id_geo) = 15
                $whereL
            ")->fetch(PDO::FETCH_ASSOC);

            $total = max(1, (int)$kpi['total']);

            // 2. Cobertura por columna (%)
            $cobertura = [];
            $cols = ['nombre','email','telefono','sexo','rango_edad','estrato',
                     'afiliacion_politica','nivel_educativo','id_geo',
                     'geo_departamento','subregion','municipio','zona','comuna_localidad','barrio','platform'];
            foreach ($cols as $col) {
                $cobertura[$col] = round((int)$kpi["c_$col"] / $total * 100, 1);
            }

            // 3. Distribuciones categóricas
            $dist = [];
            $distFields = [
                'sexo'               => 'SELECT sexo AS val, COUNT(*) AS cnt FROM import_log_leads WHERE sexo IS NOT NULL ' . $whereAnd . ' GROUP BY sexo ORDER BY cnt DESC',
                'rango_edad'         => 'SELECT rango_edad AS val, COUNT(*) AS cnt FROM import_log_leads WHERE rango_edad IS NOT NULL ' . $whereAnd . ' GROUP BY rango_edad ORDER BY FIELD(rango_edad,"18a24","25a34","35a44","45a65+","18-24","25-34","35-44","45-65+") ASC',
                'estrato'            => 'SELECT estrato AS val, COUNT(*) AS cnt FROM import_log_leads WHERE estrato IS NOT NULL ' . $whereAnd . ' GROUP BY estrato ORDER BY estrato ASC',
                'afiliacion_politica'=> 'SELECT afiliacion_politica AS val, COUNT(*) AS cnt FROM import_log_leads WHERE afiliacion_politica IS NOT NULL ' . $whereAnd . ' GROUP BY afiliacion_politica ORDER BY cnt DESC LIMIT 15',
                'nivel_educativo'    => 'SELECT nivel_educativo AS val, COUNT(*) AS cnt FROM import_log_leads WHERE nivel_educativo IS NOT NULL ' . $whereAnd . ' GROUP BY nivel_educativo ORDER BY cnt DESC LIMIT 12',
                'platform'           => 'SELECT COALESCE(platform,"desconocido") AS val, COUNT(*) AS cnt FROM import_log_leads ' . $where . ' GROUP BY platform ORDER BY cnt DESC',
            ];

            foreach ($distFields as $key => $sql) {
                $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                $dist[$key] = array_map(fn($r) => ['val' => $r['val'], 'cnt' => (int)$r['cnt']], $rows);
            }

            // 4. Jerarquía geográfica — via c98_geo, estrategia LEFT(id_geo, N)
            // id_geo VARCHAR(15): LEFT(5)=mpio, LEFT(8)=zona, LEFT(11)=comuna, [15]=barrio
            // Formato: "valor|||padre"
            $geo = [];
            $geoLevels = [
                'departamento' => "SELECT dpto.nombre AS val, COUNT(*) AS cnt
                    FROM import_log_leads l
                    JOIN c98_geo.dim_departamento dpto ON dpto.id = LEFT(l.id_geo, 2)
                    WHERE l.id_geo IS NOT NULL $whereAndL
                    GROUP BY dpto.id ORDER BY cnt DESC LIMIT 30",

                'subregion'    => "SELECT
                    CONCAT(sr.nombre, '|||', COALESCE(dpto.nombre,'?')) AS val,
                    COUNT(*) AS cnt
                    FROM import_log_leads l
                    JOIN c98_geo.dim_municipio    m    ON m.id    = LEFT(l.id_geo, 5)
                    JOIN c98_geo.dim_subregion    sr   ON sr.id   = m.id_subregion
                    JOIN c98_geo.dim_departamento dpto ON dpto.id = m.id_departamento
                    WHERE l.id_geo IS NOT NULL $whereAndL
                    GROUP BY sr.id, dpto.id ORDER BY cnt DESC LIMIT 30",

                'municipio'    => "SELECT
                    CONCAT(m.nombre, '|||', COALESCE(dpto.nombre,'?')) AS val,
                    COUNT(*) AS cnt
                    FROM import_log_leads l
                    JOIN c98_geo.dim_municipio    m    ON m.id    = LEFT(l.id_geo, 5)
                    JOIN c98_geo.dim_departamento dpto ON dpto.id = m.id_departamento
                    WHERE l.id_geo IS NOT NULL $whereAndL
                    GROUP BY m.id, dpto.id ORDER BY cnt DESC LIMIT 30",

                'zona'         => "SELECT
                    CONCAT(z.nombre, '|||', COALESCE(m.nombre,'?')) AS val,
                    COUNT(*) AS cnt
                    FROM import_log_leads l
                    JOIN c98_geo.dim_zona      z ON z.id = LEFT(l.id_geo, 8)
                    JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
                    WHERE CHAR_LENGTH(l.id_geo) >= 8 $whereAndL
                    GROUP BY z.id, m.id ORDER BY cnt DESC LIMIT 30",

                'comuna'       => "SELECT
                    CONCAT(c.nombre, '|||', COALESCE(m.nombre,'?')) AS val,
                    COUNT(*) AS cnt
                    FROM import_log_leads l
                    JOIN c98_geo.dim_comuna    c ON c.id  = LEFT(l.id_geo, 11)
                    JOIN c98_geo.dim_zona      z ON z.id  = c.id_zona
                    JOIN c98_geo.dim_municipio m ON m.id  = z.id_municipio
                    WHERE CHAR_LENGTH(l.id_geo) >= 11 $whereAndL
                    GROUP BY c.id, m.id ORDER BY cnt DESC LIMIT 30",

                // Barrio: padre compuesto = "Ciudad - Zona - Comuna"
                'barrio'       => "SELECT
                    CONCAT(b.nombre, '|||',
                        CONCAT_WS(' - ', m.nombre, z.nombre, c.nombre)
                    ) AS val,
                    COUNT(*) AS cnt
                    FROM import_log_leads l
                    JOIN c98_geo.dim_barrio    b ON b.id   = l.id_geo
                    JOIN c98_geo.dim_comuna    c ON c.id   = b.id_comuna
                    JOIN c98_geo.dim_zona      z ON z.id   = c.id_zona
                    JOIN c98_geo.dim_municipio m ON m.id   = z.id_municipio
                    WHERE CHAR_LENGTH(l.id_geo) = 15 $whereAndL
                    GROUP BY b.id, m.id, z.id, c.id ORDER BY cnt DESC LIMIT 40",
            ];
            foreach ($geoLevels as $nivel => $sql) {
                $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                $geo[$nivel] = array_map(fn($r) => ['val' => $r['val'], 'cnt' => (int)$r['cnt']], $rows);
            }

            // 5. Timeline de importaciones (últimas 30)
            $timeline = $db->query("
                SELECT DATE(importado_en) AS fecha, SUM(filas_ins) AS nuevos,
                       SUM(filas_dup) AS duplicados, COUNT(*) AS importaciones
                FROM import_log
                GROUP BY DATE(importado_en)
                ORDER BY fecha DESC
                LIMIT 30
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 6. Leads por importación (top 20)
            $porImport = $db->query("
                SELECT il.id, il.archivo, il.hoja, DATE(il.importado_en) AS fecha,
                       il.filas_ins AS nuevos, il.filas_dup AS dup,
                       COALESCE(il.tipo,'leads') AS tipo, il.etapa
                FROM import_log il
                ORDER BY il.id DESC LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);

            jsonOut([
                'ok'          => true,
                'total'       => $total,
                'kpi'         => $kpi,
                'cobertura'   => $cobertura,
                'dist'        => $dist,
                'geo'         => $geo,
                'timeline'    => array_reverse($timeline),
                'por_import'  => $porImport,
            ]);
        } catch (\Throwable $e) {
            errOut('Error en analytics: ' . $e->getMessage());
        }
    }

    // ── GET_DIMS ──────────────────────────────────────────────
    if ($action === 'get_dims') {
        $db = getDB();
        $result = [];

        // ── dim_candidato: introspect columnas reales para compatibilidad ──
        try {
            // Detectar columna de nombre en dim_candidato
            $candCols = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dim_candidato'
                ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
            $nameCol = in_array('nombre_completo', $candCols) ? 'nombre_completo'
                     : (in_array('candidato', $candCols)     ? 'candidato' : ($candCols[1] ?? 'nombre_completo'));
            $ptIdCol = in_array('id_partido', $candCols)    ? 'id_partido'
                     : (in_array('partido_id', $candCols)   ? 'partido_id' : null);

            // Detectar columna de nombre en dim_partido
            $ptCols  = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dim_partido'
                ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
            $ptNameCol = in_array('nombre_partido', $ptCols) ? 'nombre_partido'
                       : (in_array('partido', $ptCols)      ? 'partido' : ($ptCols[1] ?? 'nombre_partido'));
            $ptSiglaCol = in_array('sigla', $ptCols) ? 'sigla' : null;

            if ($ptIdCol) {
                $siglaExpr = $ptSiglaCol ? ", dp.`$ptSiglaCol` AS sigla" : "";
                $rows = $db->query("
                    SELECT dc.id_candidato,
                           dc.`$nameCol`    AS candidato,
                           dc.`$ptIdCol`    AS partido_id,
                           dp.`$ptNameCol`  AS partido
                           $siglaExpr
                    FROM   dim_candidato dc
                    LEFT JOIN dim_partido dp ON dp.id_partido = dc.`$ptIdCol`
                    ORDER  BY dc.`$nameCol`
                ")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = $db->query("SELECT id_candidato, `$nameCol` AS candidato FROM dim_candidato ORDER BY `$nameCol`")
                           ->fetchAll(PDO::FETCH_ASSOC);
            }
            $result['dim_candidato'] = array_map(fn($r) => [
                'id'         => $r['id_candidato'],
                'nombre'     => $r['candidato'],
                'partido_id' => $r['partido_id'] ?? null,
                'partido'    => $r['partido']    ?? null,
                'sigla'      => $r['sigla']      ?? null,
            ], $rows);
        } catch (\Exception $e) {
            $result['dim_candidato'] = [];
        }

        // ── tipos_eleccion: distinct tipo_eleccion desde dim_candidatura ──
        try {
            $rows = $db->query("
                SELECT DISTINCT tipo_eleccion, anio_eleccion
                FROM dim_candidatura
                WHERE tipo_eleccion IS NOT NULL AND tipo_eleccion != ''
                ORDER BY anio_eleccion DESC, tipo_eleccion
            ")->fetchAll(PDO::FETCH_ASSOC);
            $result['tipos_eleccion'] = array_map(fn($r) => [
                'tipo' => $r['tipo_eleccion'],
                'anio' => $r['anio_eleccion'],
                'label'=> $r['tipo_eleccion'] . ' ' . $r['anio_eleccion'],
            ], $rows);
        } catch (\Exception $e) {
            $result['tipos_eleccion'] = [];
        }

        // ── candidatos_por_tipo: {tipo_eleccion: [id_candidato, ...]} ──
        try {
            $rows = $db->query("
                SELECT tipo_eleccion, id_candidato
                FROM dim_candidatura
                WHERE tipo_eleccion IS NOT NULL
                ORDER BY tipo_eleccion
            ")->fetchAll(PDO::FETCH_ASSOC);
            $byTipo = [];
            foreach ($rows as $r) {
                $byTipo[$r['tipo_eleccion']][] = (int)$r['id_candidato'];
            }
            $result['candidatos_por_tipo'] = $byTipo;
        } catch (\Exception $e) {
            $result['candidatos_por_tipo'] = [];
        }

        // ── dim_partido ──
        try {
            $rows = $db->query("SELECT id_partido, nombre_partido FROM dim_partido WHERE activo=1 ORDER BY nombre_partido")
                       ->fetchAll(PDO::FETCH_NUM);
            $result['dim_partido'] = array_map(fn($r) => ['id' => $r[0], 'nombre' => $r[1]], $rows);
        } catch (\Exception $e) {
            try {
                $ptCols = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dim_partido'
                    ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
                $ptName = in_array('nombre_partido', $ptCols) ? 'nombre_partido' : ($ptCols[1] ?? 'nombre_partido');
                $rows = $db->query("SELECT id_partido, `$ptName` FROM dim_partido ORDER BY `$ptName`")
                           ->fetchAll(PDO::FETCH_NUM);
                $result['dim_partido'] = array_map(fn($r) => ['id' => $r[0], 'nombre' => $r[1]], $rows);
            } catch (\Exception $e2) { $result['dim_partido'] = []; }
        }

        // ── Municipios desde c98_geo ──
        try {
            $rows = $db->query("
                SELECT m.id,
                       CONCAT(COALESCE(d.nombre,''), ' · ', COALESCE(m.nombre,'')) AS label
                FROM   c98_geo.dim_municipio m
                LEFT JOIN c98_geo.dim_departamento d ON d.id = m.id_departamento
                ORDER BY d.nombre, m.nombre
            ")->fetchAll(PDO::FETCH_NUM);
            $result['municipios'] = array_map(fn($r) => ['id' => $r[0], 'nombre' => $r[1]], $rows);
        } catch (\Exception $e) {
            $result['municipios'] = [];
        }

        jsonOut(['ok' => true, 'dims' => $result]);
    }

    // ── RESET_DB ──────────────────────────────────────────────
    // Elimina TODOS los leads e historial. Requiere confirmación explícita.
    if ($action === 'reset_db') {
        $confirm = $_POST['confirm'] ?? '';
        if ($confirm !== 'BORRAR_TODO') errOut('Confirmación incorrecta.');
        $db = getDB();
        try {
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            $db->exec("TRUNCATE TABLE import_log_leads_respuestas");
            $db->exec("TRUNCATE TABLE import_log_leads");
            $db->exec("TRUNCATE TABLE import_log");
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            jsonOut(['ok' => true, 'message' => 'Base de datos limpiada. Todos los leads e historial eliminados.']);
        } catch (\Throwable $e) {
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            errOut('Error limpiando BD: ' . $e->getMessage());
        }
    }

    // ── DELETE_IMPORT ────────────────────────────────────────
    if ($action === 'delete_import') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) errOut('ID de importación inválido.');
        $db = getDB();
        try {
            // El CASCADE chain maneja todo automáticamente:
            //   import_log → import_log_leads (CASCADE)
            //              → import_electoral  (CASCADE)
            //   import_log_leads → import_log_leads_respuestas (CASCADE)
            $db->prepare("DELETE FROM import_log WHERE id = ?")->execute([$id]);
            jsonOut(['ok' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            errOut('Error eliminando importación: ' . $e->getMessage());
        }
    }

    // ── LOGS ─────────────────────────────────────────────────
    if ($action === 'logs') {
        $db = getDB();
        // Recrear view si está rota (schema de import_electoral cambió)
        $db->exec("CREATE OR REPLACE VIEW v_import_resumen AS
            SELECT il.id, il.archivo, il.hoja, il.importado_en,
                   il.filas_proc, il.filas_ins, il.filas_dup, il.filas_err,
                   il.notas, il.etapa, il.url_carpeta, il.url_archivo,
                   COALESCE(il.tipo,'leads') AS tipo,
                   COUNT(DISTINCT l.id)  AS leads_en_bd,
                   COUNT(DISTINCT ie.id) AS registros_electorales
            FROM import_log il
            LEFT JOIN import_log_leads l  ON l.import_id  = il.id
            LEFT JOIN import_electoral ie ON ie.import_id = il.id
            GROUP BY il.id ORDER BY il.importado_en DESC");
        try {
            $rows = $db->query("SELECT * FROM v_import_resumen LIMIT 50")->fetchAll();
            jsonOut(['ok' => true, 'logs' => $rows]);
        } catch (\Throwable $e) {
            jsonOut(['ok' => true, 'logs' => [], 'view_error' => $e->getMessage()]);
        }
    }

    // ── SET_ETAPA ─────────────────────────────────────────────
    if ($action === 'set_etapa') {
        $id    = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $etapa = trim($_POST['etapa'] ?? $_GET['etapa'] ?? '');
        $etapasValidas = ['importado','validando','enriquecido','analizado','reportado','archivado'];
        if (!$id) errOut('Falta id.');
        if ($etapa !== '' && !in_array($etapa, $etapasValidas)) errOut('Etapa inválida.');
        $db = getDB();
        $db->prepare("UPDATE import_log SET etapa = ? WHERE id = ?")
           ->execute([$etapa ?: null, $id]);
        jsonOut(['ok' => true, 'id' => $id, 'etapa' => $etapa ?: null]);
    }

    // ── SET_URL_CARPETA ───────────────────────────────────────
    if ($action === 'set_url_carpeta') {
        $id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $url = trim($_POST['url'] ?? $_GET['url'] ?? '');
        if (!$id) errOut('Falta id.');
        $db = getDB();
        $db->prepare("UPDATE import_log SET url_carpeta = ? WHERE id = ?")
           ->execute([$url ?: null, $id]);
        jsonOut(['ok' => true, 'id' => $id, 'url_carpeta' => $url ?: null]);
    }

    // ── SET_URL_ARCHIVO ───────────────────────────────────────
    if ($action === 'set_url_archivo') {
        $id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $url = trim($_POST['url'] ?? $_GET['url'] ?? '');
        if (!$id) errOut('Falta id.');
        $db = getDB();
        $db->prepare("UPDATE import_log SET url_archivo = ? WHERE id = ?")
           ->execute([$url ?: null, $id]);
        jsonOut(['ok' => true, 'id' => $id, 'url_archivo' => $url ?: null]);
    }

    // ── GET_SHEETS ────────────────────────────────────────────
    if ($action === 'get_sheets') {
        $tmpPath = handleUpload();
        $sheets  = xlsxGetSheets($tmpPath); // lanza RuntimeException si falla
        $result  = ['ok' => true, 'sheets' => $sheets];

        // Detectar hojas ya importadas del mismo archivo (por nombre original)
        $origName     = $_FILES['file']['name'] ?? '';
        $importedRows = [];
        try {
            $db   = getDB();
            $stmt = $db->prepare(
                "SELECT hoja, MAX(importado_en) AS ultima_carga, SUM(filas_ins) AS leads_ins,
                        MAX(url_archivo) AS url_archivo, MAX(url_carpeta) AS url_carpeta
                 FROM import_log WHERE archivo = ?
                 GROUP BY hoja"
            );
            $stmt->execute([$origName]);
            $importedRows = $stmt->fetchAll();
        } catch (\Throwable $e) { /* silencioso si BD no disponible */ }

        jsonOut([
            'ok'             => true,
            'temp_id'        => basename($tmpPath),
            'sheets'         => $result['sheets'],
            'imported_sheets'=> $importedRows,   // [{hoja, ultima_carga, leads_ins, url_archivo, url_carpeta}]
        ]);
    }

    // ── PREVIEW ───────────────────────────────────────────────
    if ($action === 'preview') {
        $tempId = basename($_POST['temp_id'] ?? '');
        $sheet  = $_POST['sheet'] ?? '';
        if (!$tempId || !$sheet) errOut('Falta temp_id o sheet.');
        $tmpPath = TMP_DIR . $tempId;
        if (!file_exists($tmpPath)) errOut('Archivo temporal no encontrado.', 404);

        $result = xlsxReadRows($tmpPath, $sheet, 21); // 1 header + 20 data rows
        if (!($result['ok'] ?? false)) throw new RuntimeException($result['error'] ?? 'Error leyendo hoja');

        $rows = $result['rows'] ?? [];
        if (empty($rows)) errOut("Hoja '$sheet' vacía.");

        $headers = $rows[0];
        $data    = array_slice($rows, 1);

        $columns = [];
        $hasLeadIdCol = false;
        foreach ($headers as $i => $h) {
            $hStr = ($h !== null && trim((string)$h) !== '') ? (string)$h : null;
            $type = $hStr ? detectColType($hStr) : '__skip';
            if ($type === 'lead_id') $hasLeadIdCol = true;
            $columns[] = ['index' => $i, 'header' => $hStr, 'type' => $type, 'auto' => true];
        }
        // Fallback: si ningún header se detectó como lead_id, usar col 0
        if (!$hasLeadIdCol && !empty($columns)) {
            $columns[0]['type'] = 'lead_id';
        }

        $stats = ['known' => 0, 'eav' => 0, 'skip' => 0];
        foreach ($columns as $c) {
            if ($c['type'] === '__skip')    $stats['skip']++;
            elseif ($c['type'] === '__eav') $stats['eav']++;
            else                            $stats['known']++;
        }

        jsonOut(['ok' => true, 'temp_id' => $tempId, 'sheet' => $sheet,
                 'columns' => $columns, 'preview' => $data, 'stats' => $stats]);
    }

    // ── IMPORT (multi-hoja + extracciones) ───────────────────
    if ($action === 'import') {
        $tempId      = basename($_POST['temp_id'] ?? '');
        // Acepta sheets[] (array) o sheet (string, backward-compat)
        $sheetsParam = json_decode($_POST['sheets'] ?? '[]', true) ?? [];
        if (empty($sheetsParam) && !empty($_POST['sheet'])) $sheetsParam = [$_POST['sheet']];
        $archivo     = $_POST['archivo'] ?? $tempId;
        $notas       = $_POST['notas'] ?? null;
        $urlCarpeta  = $_POST['url_carpeta'] ?? null;
        $urlArchivo  = $_POST['url_archivo'] ?? null;
        // Contexto electoral (opcional — solo si la encuesta tiene campos electorales)
        $electoralCtx = json_decode($_POST['electoral_ctx'] ?? 'null', true);
        $tipoImport   = ($electoralCtx && !empty($electoralCtx['tipo_encuesta'])) ? 'electoral' : 'leads';
        // sheet_mappings: {sheetName: {colIndex: 'fieldName'|'__eav'|'__skip'}}
        // Soporta tanto el nuevo formato (sheet_mappings) como fallback plano (mappings)
        $sheetMappingsParam = json_decode($_POST['sheet_mappings'] ?? 'null', true);
        $mappingsFallback   = json_decode($_POST['mappings'] ?? '{}', true) ?? [];
        // [{"from":"adset_name","delimiter":"_","position":0,"target":"municipio_zona"},...]
        $extractions = json_decode($_POST['extractions'] ?? '[]', true) ?? [];
        // Extracción automática de ubicación — opt-in, apagada por defecto (ver autoParseLeadGeo)
        $autoGeoEnabled = ($_POST['auto_geo_enabled'] ?? '0') === '1';
        $autoGeoSource  = $_POST['auto_geo_source'] ?? 'adset_name';
        $autoGeoLevels  = $autoGeoEnabled ? (json_decode($_POST['auto_geo_levels'] ?? '[]', true) ?? []) : [];

        if (!$tempId || empty($sheetsParam)) errOut('Falta temp_id o sheet.');
        $tmpPath = TMP_DIR . $tempId;
        if (!file_exists($tmpPath)) errOut('Archivo temporal no encontrado.', 404);

        $db = getDB();

        // Auto-migración: agrega columnas nuevas si no existen (idempotente)
        foreach ([
            "ALTER TABLE import_log ADD COLUMN url_archivo     VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE import_log ADD COLUMN url_carpeta     VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE import_log ADD COLUMN etapa           VARCHAR(50)  DEFAULT 'importado'",
            "ALTER TABLE import_log ADD COLUMN tipo            VARCHAR(20)  DEFAULT 'leads'",
            "ALTER TABLE import_log_leads      ADD COLUMN nivel_educativo  VARCHAR(100) DEFAULT NULL",
            "ALTER TABLE import_log_leads      ADD COLUMN geo_departamento VARCHAR(100) DEFAULT NULL",
            "ALTER TABLE import_log_leads      ADD COLUMN municipio        VARCHAR(100) DEFAULT NULL",
            "ALTER TABLE import_log_leads      ADD COLUMN zona             VARCHAR(100) DEFAULT NULL",
            "ALTER TABLE import_log_leads      ADD COLUMN comuna_localidad VARCHAR(100) DEFAULT NULL",
            // Catálogo de preguntas: array denormalizado de import_ids donde aparece cada pregunta
            "ALTER TABLE import_log_leads_preguntas ADD COLUMN import_ids JSON NULL COMMENT 'Array de import_ids que usan esta pregunta'",
        ] as $ddl) {
            try { $db->exec($ddl); } catch (\Exception $e) { /* columna ya existe */ }
        }

        // Si aún existe la columna `pregunta` en respuestas (instancia sin migrar),
        // ejecutar la migración EAV ahora de forma automática
        try {
            $hasPregCol = $db->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='import_log_leads_respuestas'
                 AND COLUMN_NAME='pregunta'"
            )->fetchColumn();
            if ($hasPregCol) {
                // Asegurar tabla catálogo
                $db->exec("CREATE TABLE IF NOT EXISTS import_log_leads_preguntas (
                    id       SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    pregunta VARCHAR(500) NOT NULL,
                    import_ids JSON NULL,
                    UNIQUE KEY uk_pregunta (pregunta(255))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $db->exec("INSERT IGNORE INTO import_log_leads_preguntas (pregunta)
                           SELECT DISTINCT pregunta FROM import_log_leads_respuestas WHERE pregunta IS NOT NULL");
                // Agregar pregunta_id si no existe
                try { $db->exec("ALTER TABLE import_log_leads_respuestas ADD COLUMN pregunta_id SMALLINT UNSIGNED NULL AFTER id"); } catch(\Exception $e){}
                try { $db->exec("ALTER TABLE import_log_leads_respuestas ADD COLUMN import_id INT NULL AFTER id"); } catch(\Exception $e){}
                $db->exec("UPDATE import_log_leads_respuestas r
                           JOIN import_log_leads_preguntas p ON p.pregunta = r.pregunta
                           SET r.pregunta_id = p.id WHERE r.pregunta_id IS NULL");
                $db->exec("UPDATE import_log_leads_respuestas r
                           JOIN import_log_leads l ON l.id = r.lead_id
                           SET r.import_id = l.import_id WHERE r.import_id IS NULL");
                try { $db->exec("ALTER TABLE import_log_leads_respuestas DROP COLUMN pregunta"); } catch(\Exception $e){}
            }
        } catch (\Exception $e) { /* ignorar */ }

        // Migración automática: si import_electoral tiene el schema viejo (columna tipo_encuesta),
        // la recrea con la estructura normalizada (una fila por pregunta × candidato)
        try {
            $hasOldCol = $db->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME='import_electoral'
                   AND COLUMN_NAME='tipo_encuesta'"
            )->fetchColumn();
            if ($hasOldCol) {
                $db->exec("DROP TABLE import_electoral");
            }
        } catch (\Exception $e) { /* ignorar */ }

        // Crear tabla import_electoral si no existe (estructura normalizada)
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS import_electoral (
                id                INT AUTO_INCREMENT PRIMARY KEY,
                import_id         INT NOT NULL,
                id_participacion  INT NULL,
                electoral_objetivo VARCHAR(60) NOT NULL,
                pregunta          VARCHAR(500) NOT NULL,
                id_candidato      INT NULL,
                total_respuestas  INT NOT NULL DEFAULT 0,
                creado_en         DATETIME DEFAULT NOW(),
                CONSTRAINT fk_elec_import
                  FOREIGN KEY (import_id) REFERENCES import_log(id)
                  ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_elec_participacion
                  FOREIGN KEY (id_participacion) REFERENCES dim_participacion(id_participacion)
                  ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_elec_candidato
                  FOREIGN KEY (id_candidato) REFERENCES dim_candidato(id_candidato)
                  ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Exception $e) { /* ya existe con schema correcto */ }

        // dim_geo fue eliminada — la geo se resuelve via c98_geo (cross-BD)
        // No se hace nada aquí; resolveGeoId() usa c98_geo directamente.

        // Migración geo: copiar municipio_zona → municipio (si municipio_zona existe y municipio está vacío)
        try {
            $hasMuniZona = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='import_log_leads'
                AND COLUMN_NAME='municipio_zona'")->fetchColumn();
            if ($hasMuniZona) {
                $db->exec("UPDATE import_log_leads SET municipio = municipio_zona
                           WHERE municipio IS NULL AND municipio_zona IS NOT NULL AND municipio_zona != ''");
                $db->exec("ALTER TABLE import_log_leads DROP COLUMN municipio_zona");
            }
        } catch (\Exception $e) { /* ignorar si ya fue migrado */ }

        // Migración de tipo: BIGINT → VARCHAR(64) en leads.id y import_log_leads_respuestas.lead_id
        // Necesario para soportar IDs alfanuméricos, cédulas cortas, UUIDs, etc.
        try {
            $col = $db->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='import_log_leads' AND COLUMN_NAME='id'")->fetchColumn();
            if ($col && strtolower($col) !== 'varchar') {
                $db->exec("SET FOREIGN_KEY_CHECKS = 0");
                // Primero import_log_leads_respuestas (FK hijo)
                try { $db->exec("ALTER TABLE import_log_leads_respuestas DROP FOREIGN KEY fk_resp_lead"); } catch (\Exception $e) {}
                $db->exec("ALTER TABLE import_log_leads_respuestas MODIFY COLUMN lead_id VARCHAR(64) NOT NULL");
                $db->exec("ALTER TABLE import_log_leads MODIFY COLUMN id VARCHAR(64) NOT NULL");
                // Restaurar FK con tipos coincidentes
                try {
                    $db->exec("ALTER TABLE import_log_leads_respuestas ADD CONSTRAINT fk_resp_lead
                        FOREIGN KEY (lead_id) REFERENCES import_log_leads(id) ON DELETE CASCADE");
                } catch (\Exception $e) {}
                $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
        } catch (\Exception $e) { /* ignorar si ya fue migrado */ }

        // Actualizar vista — compatible con schema nuevo de import_electoral
        try {
            $db->exec("CREATE OR REPLACE VIEW v_import_resumen AS
                SELECT il.id, il.archivo, il.hoja, il.importado_en,
                       il.filas_proc, il.filas_ins, il.filas_dup, il.filas_err,
                       il.notas, il.etapa, il.url_carpeta, il.url_archivo,
                       COALESCE(il.tipo,'leads') AS tipo,
                       COUNT(DISTINCT l.id)  AS leads_en_bd,
                       COUNT(DISTINCT ie.id) AS registros_electorales
                FROM import_log il
                LEFT JOIN import_log_leads l  ON l.import_id  = il.id
                LEFT JOIN import_electoral ie ON ie.import_id = il.id
                GROUP BY il.id ORDER BY il.importado_en DESC");
        } catch (\Exception $e) { /* ignorar */ }

        $allResults = [];
        $stmtLead = $db->prepare("
            INSERT IGNORE INTO import_log_leads
                (id, created_time, ad_id, ad_name, adset_id, adset_name,
                 campaign_id, campaign_name, form_id, form_name,
                 is_organic, platform, nombre, email, telefono,
                 sexo, rango_edad, estrato, afiliacion_politica,
                 nivel_educativo, id_geo, import_id)
            VALUES
                (:id, :created_time, :ad_id, :ad_name, :adset_id, :adset_name,
                 :campaign_id, :campaign_name, :form_id, :form_name,
                 :is_organic, :platform, :nombre, :email, :telefono,
                 :sexo, :rango_edad, :estrato, :afiliacion_politica,
                 :nivel_educativo, :id_geo, :import_id)
        ");
        // EAV: usar pregunta_id (FK al catálogo) + import_id directo
        // es_electoral se detecta por keywords en el texto de la pregunta
        $electoralKeywords = ['candidat','votar','voto','presiden','alcald','gobernado',
            'concej','senado','congres','partido pol','elecci','sufrag','confiar',
            'intencion','imagen candidat','aprueba','campana pol'];
        $stmtInsPreg = $db->prepare(
            "INSERT IGNORE INTO import_log_leads_preguntas (pregunta, es_electoral) VALUES (?, ?)"
        );
        $stmtGetPreg = $db->prepare(
            "SELECT id FROM import_log_leads_preguntas WHERE pregunta = ?"
        );
        $stmtEav = $db->prepare(
            "INSERT INTO import_log_leads_respuestas (lead_id, pregunta_id, respuesta) VALUES (?, ?, ?)"
        );
        // Para leads duplicados que ya existen en BD: recuperar su id_lead
        $stmtGetLeadNumId = $db->prepare(
            "SELECT id_lead FROM import_log_leads WHERE id = ?"
        );
        $pregIdCache = []; // pregunta_text → pregunta_id (evita N+1 para misma pregunta)

        // Campos opcionales extraíbles vía reglas
        $optFields = ['nombre','email','telefono','sexo','rango_edad','estrato',
                      'afiliacion_politica','nivel_educativo',
                      'geo_departamento','subregion','municipio','zona','comuna_localidad','barrio'];

        foreach ($sheetsParam as $sheet) {
            $result = xlsxReadRows($tmpPath, $sheet);
            if (!($result['ok'] ?? false)) {
                $allResults[] = ['sheet'=>$sheet,'ok'=>false,'error'=>$result['error']??'Error'];
                continue;
            }
            $allRows = $result['rows'] ?? [];
            if (empty($allRows)) { $allResults[] = ['sheet'=>$sheet,'ok'=>false,'error'=>'Hoja vacía']; continue; }

            $headers  = $allRows[0];
            $dataRows = array_slice($allRows, 1);

            // Mapeo por hoja: usar sheet_mappings[sheet] si existe, si no fallback plano, si no auto-detect
            $sheetMappingOverrides = (is_array($sheetMappingsParam) && isset($sheetMappingsParam[$sheet]))
                ? $sheetMappingsParam[$sheet]
                : $mappingsFallback;

            // Detectar tipos de columna (mapeo individual por hoja override auto-detect)
            $colTypes = [];
            $hasLeadIdCol = false;
            foreach ($headers as $i => $h) {
                // Siempre ignorar lead_status y retailer_item_id
                $hLower = ($h !== null) ? strtolower(trim((string)$h)) : '';
                if (in_array($hLower, ['lead_status', 'retailer_item_id'])) {
                    $colTypes[$i] = '__skip'; continue;
                }
                // Usar override del usuario si existe para esta columna
                $iStr = (string)$i;
                if (isset($sheetMappingOverrides[$iStr])) { $colTypes[$i] = $sheetMappingOverrides[$iStr]; }
                elseif (isset($sheetMappingOverrides[$i])) { $colTypes[$i] = $sheetMappingOverrides[$i]; }
                else {
                    $hStr = ($h !== null && trim((string)$h) !== '') ? (string)$h : null;
                    $colTypes[$i] = $hStr ? detectColType($hStr) : '__skip';
                }
                if ($colTypes[$i] === 'lead_id') $hasLeadIdCol = true;
            }
            // Fallback: si ningún header fue detectado como lead_id, usar col 0
            if (!$hasLeadIdCol) {
                $colTypes[0] = 'lead_id';
            }

            $db->beginTransaction();
            try {
                $stmtLog = $db->prepare(
                    "INSERT INTO import_log (archivo, hoja, notas, etapa, url_carpeta, url_archivo, tipo) VALUES (?, ?, ?, 'importado', ?, ?, ?)"
                );
                $stmtLog->execute([$archivo, $sheet, $notas, $urlCarpeta ?: null, $urlArchivo ?: null, $tipoImport]);
                $importId = (int)$db->lastInsertId();

                $proc = 0; $ins = 0; $dup = 0; $dup_file = 0; $dup_db = 0; $err = 0; $enriched = 0; $null_meta = 0;
                $dimGeoReady = true; // geo se resuelve via c98_geo siempre
                $seenIds    = []; // IDs vistos en ESTA importación (col 0)
                $seenEmails = []; // Emails vistos en filas donde col 0 estaba vacía
                // Stmt para verificar email en BD cuando col 0 está vacía
                $stmtCheckEmail = $db->prepare("SELECT id FROM import_log_leads WHERE email = ? LIMIT 1");

                foreach ($dataRows as $cells) {
                    $nonEmpty = array_filter($cells, fn($v) => $v !== null && (string)$v !== '');
                    if (empty($nonEmpty)) continue;
                    $proc++;

                    $lead = [
                        'id' => null, 'created_time' => null,
                        'ad_id' => null, 'ad_name' => null,
                        'adset_id' => null, 'adset_name' => null,
                        'campaign_id' => null, 'campaign_name' => null,
                        'form_id' => null, 'form_name' => null,
                        'is_organic' => 0, 'platform' => null,
                        'nombre' => null, 'email' => null, 'telefono' => null,
                        'sexo' => null, 'rango_edad' => null, 'estrato' => null,
                        'afiliacion_politica' => null, 'nivel_educativo' => null,
                        'geo_departamento' => null, 'subregion' => null,
                        'municipio' => null, 'zona' => null,
                        'comuna_localidad' => null, 'barrio' => null,
                        'id_geo' => null,
                        'import_id' => $importId,
                    ];
                    $eav = [];

                    foreach ($cells as $i => $val) {
                        $type = $colTypes[$i] ?? '__skip';
                        if ($type === '__skip') continue;
                        if ($type === '__eav') {
                            $h2 = $headers[$i] ?? "col_$i";
                            if ($h2 !== null && trim((string)$h2) !== '')
                                $eav[(string)$h2] = ($val !== null) ? trim((string)$val) : null;
                            continue;
                        }
                        if ($type === 'lead_id') {
                            $lead['id'] = normalizeLeadId($val);
                        } elseif ($type === 'is_organic') {
                            $lead['is_organic'] = normalizeBool($val);
                        } elseif ($type === 'estrato') {
                            $lead['estrato'] = ($val !== null && is_numeric($val)) ? (int)$val : null;
                        } elseif ($type === 'created_time') {
                            $lead['created_time'] = ($val !== null && trim((string)$val) !== '') ? trim((string)$val) : null;
                        } elseif ($type === 'telefono') {
                            $lead['telefono'] = normalizePhone(($val !== null) ? trim((string)$val) : null);
                        } elseif ($type === 'rango_edad') {
                            $lead['rango_edad'] = normalizeRangoEdad(($val !== null) ? trim((string)$val) : null);
                        } elseif (array_key_exists($type, $lead)) {
                            // Primera columna no vacía gana — evita que una columna duplicada
                            // (ej. Meta exporta "adset_name" dos veces, la segunda detectada
                            // como "adset_name_2" por coincidencia de substring) pise el valor
                            // ya asignado por una columna anterior del mismo tipo detectado.
                            if (empty($lead[$type])) {
                                $lead[$type] = ($val !== null) ? trim((string)$val) : null;
                            }
                        } else {
                            // Tipo desconocido (ej. campos electorales) → EAV
                            $h2 = $headers[$i] ?? "col_$i";
                            if ($h2 !== null && trim((string)$h2) !== '')
                                $eav[(string)$h2] = ($val !== null) ? trim((string)$val) : null;
                        }
                    }

                    // Aplicar reglas de extracción (desde campaign/adset/ad names)
                    foreach ($extractions as $rule) {
                        $src    = $rule['from']      ?? '';
                        $delim  = $rule['delimiter']  ?? '_';
                        $pos    = (int)($rule['position'] ?? 0);
                        $target = $rule['target']    ?? '';
                        if (!$src || !$target || !in_array($target, $optFields)) continue;
                        $srcVal = $lead[$src] ?? '';
                        if (empty($srcVal) || !empty($lead[$target])) continue; // no sobreescribir
                        $parts  = array_map('trim', explode($delim, $srcVal));
                        $extracted = $parts[$pos] ?? '';
                        if ($extracted !== '') $lead[$target] = $extracted;
                    }

                    // ── Reconciliación geo (siempre — usa solo datos ya presentes) ──
                    if ($dimGeoReady) {
                        try {
                            if (reconcileLeadGeo($lead, $db)) $enriched++;
                        } catch (\Exception $e) { /* no bloquear importación */ }
                        // ── Extracción automática (opt-in, ver toggle del wizard) ──
                        if ($autoGeoEnabled && !empty($autoGeoLevels)) {
                            try {
                                if (autoParseLeadGeo($lead, $db, $autoGeoLevels, $autoGeoSource)) {
                                    $enriched++;
                                    reconcileLeadGeo($lead, $db); // propagar lo recién extraído (ej. municipio → depto)
                                }
                            } catch (\Exception $e) { /* no bloquear importación */ }
                        }
                        // Resolver FK geográfica más granular disponible
                        try {
                            $lead['id_geo'] = resolveGeoId($lead, $db);
                        } catch (\Exception $e) { /* no bloquear importación */ }
                    }

                    if (empty($lead['id'])) {
                        // Columna 0 vacía → dedup por email, luego generar ID
                        $emailVal = trim((string)($lead['email'] ?? ''));
                        if ($emailVal !== '') {
                            // Dup dentro del archivo (mismo email ya procesado en esta importación)
                            if (isset($seenEmails[$emailVal])) {
                                $dup++; $dup_file++;
                                continue;
                            }
                            // Dup en BD (email ya existe de importaciones anteriores)
                            $stmtCheckEmail->execute([$emailVal]);
                            if ($stmtCheckEmail->fetchColumn()) {
                                $dup++; $dup_db++;
                                continue;
                            }
                            $seenEmails[$emailVal] = true;
                        }
                        // Generar ID único para esta fila
                        $lead['id'] = 'gen_' . bin2hex(random_bytes(8));
                    }

                    // Detectar duplicado DENTRO del mismo archivo por ID (col 0)
                    if (isset($seenIds[$lead['id']])) {
                        $dup++; $dup_file++;
                        continue;
                    }
                    $seenIds[$lead['id']] = true;

                    // ── Rechazar filas fantasma (solo imports Meta Ads, no encuestas electorales) ──
                    // En electoral el respondente no tiene ad_id/campaign_id — es válido que sean null
                    if ($tipoImport !== 'electoral'
                        && empty($lead['ad_id']) && empty($lead['adset_id'])
                        && empty($lead['campaign_id']) && empty($lead['form_id'])) {
                        $null_meta++;
                        continue;
                    }

                    $stmtLead->execute([
                        ':id' => $lead['id'], ':created_time' => $lead['created_time'],
                        ':ad_id' => $lead['ad_id'], ':ad_name' => $lead['ad_name'],
                        ':adset_id' => $lead['adset_id'], ':adset_name' => $lead['adset_name'],
                        ':campaign_id' => $lead['campaign_id'], ':campaign_name' => $lead['campaign_name'],
                        ':form_id' => $lead['form_id'], ':form_name' => $lead['form_name'],
                        ':is_organic' => $lead['is_organic'], ':platform' => $lead['platform'],
                        ':nombre' => $lead['nombre'], ':email' => $lead['email'],
                        ':telefono' => $lead['telefono'], ':sexo' => $lead['sexo'],
                        ':rango_edad' => $lead['rango_edad'], ':estrato' => $lead['estrato'],
                        ':afiliacion_politica' => $lead['afiliacion_politica'],
                        ':nivel_educativo' => $lead['nivel_educativo'],
                        ':id_geo' => $lead['id_geo'] ?? null,
                        ':import_id' => $importId,
                    ]);

                    if ((int)$stmtLead->rowCount() === 0) {
                        // ID único en el archivo pero ya existía en BD (importación anterior)
                        $dup++; $dup_db++;
                        // Recuperar id_lead del lead existente para insertar sus respuestas EAV
                        $stmtGetLeadNumId->execute([$lead['id']]);
                        $leadNumericId = (int)($stmtGetLeadNumId->fetchColumn() ?: 0);
                    } else {
                        $ins++;
                        $leadNumericId = (int)$db->lastInsertId(); // id_lead INT PK
                    }
                    // Insertar respuestas EAV usando id_lead numérico
                    if ($leadNumericId && !empty($eav)) {
                        foreach ($eav as $preg => $resp) {
                            // Normalizar pregunta: _ → espacio, colapsar espacios, strip prefijo numérico
                            $pregNorm = trim($preg);
                            $pregNorm = str_replace('_', ' ', $pregNorm);
                            $pregNorm = preg_replace('/\s{2,}/u', ' ', $pregNorm);
                            $pregNorm = preg_replace('/^(\d+\.\s+)+/u', '', $pregNorm);
                            if ($pregNorm === '') $pregNorm = $preg; // fallback si quedó vacío

                            // Resolver pregunta_id desde catálogo (con cache en memoria)
                            if (!isset($pregIdCache[$pregNorm])) {
                                $pregLower  = mb_strtolower($pregNorm, 'UTF-8');
                                $isElectoral = 0;
                                foreach ($electoralKeywords as $kw) {
                                    if (str_contains($pregLower, $kw)) { $isElectoral = 1; break; }
                                }
                                $stmtInsPreg->execute([$pregNorm, $isElectoral]);
                                $stmtGetPreg->execute([$pregNorm]);
                                $pregIdCache[$pregNorm] = (int)($stmtGetPreg->fetchColumn() ?: 0);
                            }
                            if ($pregIdCache[$pregNorm]) {
                                // Normalizar respuesta: _ → espacio, trim, colapsar espacios
                                $respNorm = trim(str_replace('_', ' ', (string)$resp));
                                $respNorm = preg_replace('/\s{2,}/u', ' ', $respNorm);
                                $stmtEav->execute([$leadNumericId, $pregIdCache[$pregNorm], $respNorm]);
                            }
                        }
                    }
                }

                $db->prepare("UPDATE import_log SET filas_proc=?, filas_ins=?, filas_dup=?, filas_err=? WHERE id=?")
                   ->execute([$proc, $ins, $dup, $err, $importId]);
                if ($null_meta > 0) {
                    $nota = ' | ' . $null_meta . ' filas sin IDs Meta omitidas';
                    $db->prepare("UPDATE import_log SET notas=CONCAT(COALESCE(notas,''),?) WHERE id=?")
                       ->execute([$nota, $importId]);
                }

                // Guardar contexto electoral normalizado (una fila por pregunta × candidato)
                if ($electoralCtx && $importId) {
                    try {
                        $valueMaps   = $electoralCtx['value_maps'] ?? [];
                        $colMeta     = $electoralCtx['col_meta']   ?? [];
                        $tipoElecSel = $electoralCtx['tipo_eleccion_sel'] ?? null;

                        // Resolver id_participacion desde dim_participacion
                        $idParticipacion = null;
                        if ($tipoElecSel) {
                            $stmtP = $db->prepare(
                                "SELECT id_participacion FROM dim_participacion WHERE tipo_eleccion = ? LIMIT 1"
                            );
                            $stmtP->execute([strtoupper($tipoElecSel)]);
                            $idParticipacion = $stmtP->fetchColumn() ?: null;
                        }

                        $stmtIns = $db->prepare(
                            "INSERT INTO import_electoral
                             (import_id, id_participacion, electoral_objetivo, pregunta, id_candidato, total_respuestas)
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );

                        // Por cada columna electoral configurada (ej: intencion_voto, imagen_candidato)
                        foreach ($valueMaps as $colKey => $respMap) {
                            $meta         = $colMeta[$colKey] ?? [];
                            $electoralObj = $meta['field']   ?? 'general';
                            $pregunta     = $meta['pregunta'] ?? null;

                            if (!$pregunta) continue; // sin header no podemos cruzar con respuestas

                            // Contar respuestas por valor crudo en esta importación / pregunta
                            // Usa import_id directo + JOIN al catálogo (pregunta VARCHAR fue eliminado)
                            $stmtCnt = $db->prepare(
                                "SELECT r.respuesta, COUNT(*) AS total
                                 FROM import_log_leads_respuestas r
                                 JOIN import_log_leads_preguntas p ON p.id = r.pregunta_id
                                 WHERE r.import_id = ? AND p.pregunta = ?
                                 GROUP BY r.respuesta"
                            );
                            $stmtCnt->execute([$importId, $pregunta]);
                            // ['abelardo_de_la_espriella' => 45, 'otro_candidato' => 12, ...]
                            $respCounts = $stmtCnt->fetchAll(PDO::FETCH_KEY_PAIR);

                            // Agregar total por candidato_id según value_maps
                            $candCounts = [];
                            foreach ($respMap as $rawVal => $candInfo) {
                                $candId = $candInfo['candidato_id'] ?? null;
                                if (!$candId || (int)$candId <= 0) continue;
                                $cnt = (int)($respCounts[$rawVal] ?? 0);
                                $candCounts[(int)$candId] = ($candCounts[(int)$candId] ?? 0) + $cnt;
                            }

                            // INSERT una fila por (pregunta × candidato)
                            foreach ($candCounts as $candId => $total) {
                                $stmtIns->execute([
                                    $importId,
                                    $idParticipacion,
                                    $electoralObj,
                                    $pregunta,
                                    $candId,
                                    $total,
                                ]);
                            }
                        }
                    } catch (\Exception $e) { /* no bloquear por contexto electoral */ }
                }

                $db->commit();

                // ── Actualizar leads_ids en catálogo de preguntas ─────────────────
                // JSON array con todos los id_lead que respondieron cada pregunta
                // (recalcula solo las preguntas afectadas por este import)
                try {
                    $db->exec("SET SESSION group_concat_max_len = 10000000");
                    $db->prepare("
                        UPDATE import_log_leads_preguntas p
                        JOIN (
                            SELECT r.pregunta_id,
                                   CONCAT('[', GROUP_CONCAT(DISTINCT r.lead_id ORDER BY r.lead_id SEPARATOR ','), ']') AS ids
                            FROM import_log_leads_respuestas r
                            WHERE r.pregunta_id IN (
                                SELECT DISTINCT r2.pregunta_id
                                FROM import_log_leads_respuestas r2
                                JOIN import_log_leads l ON l.id_lead = r2.lead_id
                                WHERE l.import_id = ?
                            )
                            GROUP BY r.pregunta_id
                        ) agg ON agg.pregunta_id = p.id
                        SET p.leads_ids = agg.ids
                    ")->execute([$importId]);
                } catch (\Exception $e) { /* silencioso si columna aún no existe */ }

                $allResults[] = [
                    'sheet'       => $sheet,
                    'ok'          => true,
                    'import_id'   => $importId,
                    'procesadas'  => $proc,      // alias para JS
                    'proc'        => $proc,
                    'insertadas'  => $ins,        // alias para JS
                    'ins'         => $ins,
                    'dup'         => $dup,
                    'dup_file'    => $dup_file,
                    'dup_db'      => $dup_db,
                    'errores'     => $err,        // alias para JS
                    'err'         => $err,
                    'enriched'    => $enriched,
                    'null_meta'   => $null_meta,
                ];
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $allResults[] = ['sheet' => $sheet, 'ok' => false, 'error' => $e->getMessage()];
            }
        } // end foreach ($sheetsParam as $sheet)

        jsonOut(['ok' => true, 'results' => $allResults]);
    } // end if ($action === 'import')

    // ── Enriquecer leads existentes ──────────────────────────────────────────
    if ($action === 'enrich_existing') {
        // Nota: desde que import_log_leads dejó de persistir texto geo crudo
        // (region/geo_departamento/subregion/municipio/zona/comuna_localidad/barrio —
        // ver import_log_leads_geo_bkp), ya no hay de dónde reconciliar jerarquía
        // geográfica post-hoc: id_geo se calcula una sola vez, al importar. Este
        // endpoint quedó acotado a normalizar teléfono/rango_edad ya guardados.
        $db = getDB();
        $importId    = (int)($_POST['import_id'] ?? $_GET['import_id'] ?? 0);
        $importWhere = $importId ? 'AND import_id = ' . $importId : '';
        $limit       = min((int)($_GET['limit'] ?? 10000), 50000);

        $leads = $db->query("
            SELECT id, telefono, rango_edad
            FROM import_log_leads
            WHERE (
                (telefono IS NOT NULL AND LENGTH(telefono) NOT IN (7,10,11,12))
                OR (rango_edad IS NOT NULL
                    AND rango_edad NOT IN ('18a24','25a34','35a44','45a65+'))
            ) {$importWhere}
            ORDER BY id
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmtUpd = $db->prepare("UPDATE import_log_leads SET telefono=?, rango_edad=? WHERE id=?");

        $actualizados = 0;
        foreach ($leads as $row) {
            $tel  = normalizePhone($row['telefono']);
            $edad = normalizeRangoEdad($row['rango_edad']);
            if ($tel !== $row['telefono'] || $edad !== $row['rango_edad']) {
                $stmtUpd->execute([$tel, $edad, $row['id']]);
                $actualizados++;
            }
        }

        jsonOut(['ok' => true, 'revisados' => count($leads), 'actualizados' => $actualizados]);
    }

    // ── Agregar candidato rápido ──────────────────────────────────────────
    if ($action === 'add_candidato') {
        $db = getDB();
        $nombre     = trim($_POST['nombre']     ?? '');
        $idPartido  = (int)($_POST['id_partido'] ?? 0) ?: null;
        $espectro   = trim($_POST['espectro']   ?? '') ?: null;
        $tipo       = trim($_POST['tipo_eleccion'] ?? '') ?: null;
        $anio       = (int)($_POST['anio_eleccion'] ?? 0) ?: null;
        $vuelta     = (int)($_POST['vuelta']    ?? 1);

        if (!$nombre) errOut('Nombre requerido.');

        $db->beginTransaction();
        try {
            $key = preg_replace('/[^a-z0-9]+/', '_', strtolower($nombre));
            $db->prepare("INSERT INTO dim_candidato (nombre_completo, candidato_key, id_partido, espectro)
                          VALUES (?, ?, ?, ?)")
               ->execute([$nombre, $key, $idPartido, $espectro]);
            $newId = (int)$db->lastInsertId();

            if ($tipo && $anio) {
                // Mapear tipo_eleccion → cargo válido (check constraint)
                $cargoMap = [
                    'PRESIDENCIAL' => 'PRESIDENTE',
                    'TERRITORIAL'  => 'ALCALDE',
                    'CAMARA'       => 'REPRESENTANTE',
                    'SENADO'       => 'SENADO',
                    'GOBERNADOR'   => 'GOBERNADOR',
                    'ALCALDE'      => 'ALCALDE',
                    'REPRESENTANTE'=> 'REPRESENTANTE',
                    'PRESIDENTE'   => 'PRESIDENTE',
                ];
                $cargo = $cargoMap[strtoupper($tipo)] ?? null;
                if ($cargo) {
                    $db->prepare("INSERT INTO dim_candidatura (id_candidato, nombre_corto, cargo, anio_eleccion, vuelta, tipo_eleccion)
                                  VALUES (?, ?, ?, ?, ?, ?)")
                       ->execute([$newId, explode(' ', $nombre)[0], $cargo, $anio, $vuelta, strtoupper($tipo)]);
                }
            }
            $db->commit();
            jsonOut(['ok' => true, 'id' => $newId, 'nombre' => $nombre]);
        } catch (\Throwable $e) {
            $db->rollBack();
            errOut($e->getMessage());
        }
    }

    errOut('Acción no reconocida: ' . htmlspecialchars($action));

} catch (\Throwable $e) {
    errOut($e->getMessage(), 500);
}
