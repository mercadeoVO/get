<?php
// ============================================================
//  GET · Importador de Leads Meta Ads  (backend PHP nativo)
//  URL base: http://localhost/get/leads_importer.php
//
//  Acciones:
//    ?action=check        — Verifica dependencias PHP
//    ?action=get_sheets   — Recibe archivo, devuelve hojas
//    ?action=preview      — Cabeceras detectadas + muestra
//    ?action=import       — Importa todos los registros
//    ?action=logs         — Historial de importaciones
//    ?action=temas_pendientes   — Cola de revisión tema/subtema (dim_tema)
//    ?action=temas_guardar      — Confirma/corrige el tema de una pregunta
//    ?action=respuestas_muestra — Distribución de respuestas de una pregunta
//    ?action=crear_tema         — Crea un tema/subtema nuevo en dim_tema
//    ?action=municipios_lista   — Municipios de c98_geo (selector del wizard)
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

// ── Catálogo de preguntas: normalización, es_electoral, tema/subtema ────────
// Compartido entre el import real (acción 'import') y el paso "Temas" del
// wizard (acción 'registrar_preguntas') — ver app/docs/taxonomia_temas_subtemas.md §7.4

function pregNormalize(string $preg): string {
    $pregNorm = trim($preg);
    $pregNorm = str_replace('_', ' ', $pregNorm);
    $pregNorm = preg_replace('/\s{2,}/u', ' ', $pregNorm);
    $pregNorm = preg_replace('/^(\d+\.\s+)+/u', '', $pregNorm);
    return $pregNorm === '' ? $preg : $pregNorm;
}

/** Minúsculas, sin tildes ni signos, espacios colapsados: base común de la detección electoral. */
function normBasico(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Palabras clave (sin tildes) que, al INICIO de una palabra, marcan una pregunta como electoral.
 *  El prefijo de palabra evita falsos positivos como "selección" (contiene "elecci"). */
const ELECTORAL_KW_NORM = ['candidat','votar','voto','votos','presiden','alcald','gobernado','concej','senado',
    'congres','partido pol','elecci','sufrag','confiar','intencion','aprueba','campana pol',
    'precandidat','va a ganar','uribe','uribis','petrist','abstencion'];

function esElectoralPorKeyword(string $pregLower): bool {
    $n = normBasico($pregLower);
    foreach (ELECTORAL_KW_NORM as $kw) {
        if (preg_match('/(?<![a-z0-9])' . preg_quote($kw, '/') . '/', $n)) return true;
    }
    return false;
}

/** Candidatos del catálogo (dim_candidato) con su nombre normalizado. Se carga una vez por petición. */
function candidatosCatalogo(PDO $db): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($db->query("SELECT id_candidato, nombre_completo FROM dim_candidato") as $r) {
            $cache[] = ['id' => (int)$r['id_candidato'], 'nombre' => $r['nombre_completo'],
                        'norm' => normBasico($r['nombre_completo'])];
        }
    }
    return $cache;
}

const ELECTORAL_OBJETIVOS = [
    'intencion_voto'         => 'Intención de voto',
    'simpatia_partido'       => 'Simpatía de partido',
    'conocimiento_candidato' => 'Conocimiento de candidato',
    'evaluacion_candidato'   => 'Evaluación de candidato',
    'participacion_electoral'=> 'Participación electoral',
    'imagen_candidato'       => 'Imagen de candidato',
];

/** Objetivo electoral probable (clave de ELECTORAL_OBJETIVOS) según el texto normalizado, o null. */
function objetivoElectoral(string $n): ?string {
    if (preg_match('/\b(ha votado|voto en|votaste|participo|abstencion|ira a votar)\b/', $n)) return 'participacion_electoral';
    if (preg_match('/\b(conoce|conoces|conocen|conocimiento)\b/', $n))                       return 'conocimiento_candidato';
    if (preg_match('/\b(imagen|favorable|desfavorable|opinion|percepcion)\b/', $n))          return 'imagen_candidato';
    if (preg_match('/\b(aprueba|desaprueba|evalua|califica|gestion|desempeno)\b/', $n))      return 'evaluacion_candidato';
    if (preg_match('/\b(partido|simpatia|uribis\w*|petrist\w*)\b/', $n))                     return 'simpatia_partido';
    if (preg_match('/\b(votaria|votar por|intencion|por quien|voto|va a ganar)\b/', $n)) return 'intencion_voto';
    return null;
}

/**
 * Analiza si una pregunta es electoral y de qué componentes: objetivo (intención de voto,
 * conocimiento, imagen…) y candidato (del catálogo, o el nombre citado si no está).
 * confianza 'alta' = palabra clave electoral o candidato del catálogo → se marca es_electoral sola.
 * confianza 'media' = patrón "¿conoce a X?" con X fuera del catálogo → solo se sugiere (puede ser una marca).
 * @return ?array{objetivo:?string,objetivo_label:?string,candidato:?array,candidato_texto:?string,confianza:string,motivo:string}
 */
function analizarElectoral(PDO $db, string $pregLower): ?array {
    $n = normBasico($pregLower);
    if ($n === '') return null;

    $candTxt = null;
    if (preg_match('/\b(?:conoce|conoces|conocen|conocio)\s+a\s+(.+)$/', $n, $m)) $candTxt = trim($m[1]);

    $cand = null;
    $catalogo = candidatosCatalogo($db);
    foreach ($catalogo as $c) {                       // nombre completo dentro del texto
        if ($c['norm'] !== '' && str_contains(' ' . $n . ' ', ' ' . $c['norm'] . ' ')) { $cand = $c; break; }
    }
    if (!$cand && $candTxt) {                         // "conoce a X": X contenido en el nombre del candidato
        $w = explode(' ', $candTxt);
        foreach ($catalogo as $c) {
            if (!array_diff($w, explode(' ', $c['norm']))) { $cand = $c; break; }
        }
    }

    $porKeyword = esElectoralPorKeyword($pregLower);
    if (!$porKeyword && !$cand && !$candTxt) return null;

    $obj = objetivoElectoral($n);
    if (!$obj && $candTxt) $obj = 'conocimiento_candidato';
    return [
        'objetivo'        => $obj,
        'objetivo_label'  => $obj ? ELECTORAL_OBJETIVOS[$obj] : null,
        'candidato'       => $cand ? ['id' => $cand['id'], 'nombre' => $cand['nombre']] : null,
        'candidato_texto' => $candTxt,
        'confianza'       => ($porKeyword || $cand) ? 'alta' : 'media',
        'motivo'          => $porKeyword ? 'palabra clave electoral' : ($cand ? 'candidato del catálogo' : 'patrón «conoce a»'),
    ];
}

/** Columnas de clasificación de encuestas electorales en import_log (idempotente; tipo_encuesta admite varios, separados por coma). */
function ensureEncuestaCols(PDO $db): void {
    static $hecho = false;
    if ($hecho) return;
    $cols = $db->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_log'
                          AND COLUMN_NAME IN ('tipo_encuesta','lista_eleccion','nivel_eleccion')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!isset($cols['tipo_encuesta']))      $db->exec("ALTER TABLE import_log ADD COLUMN tipo_encuesta VARCHAR(120) DEFAULT NULL");
    elseif ((int)$cols['tipo_encuesta'] < 120) $db->exec("ALTER TABLE import_log MODIFY tipo_encuesta VARCHAR(120) DEFAULT NULL");
    if (!isset($cols['lista_eleccion']))     $db->exec("ALTER TABLE import_log ADD COLUMN lista_eleccion VARCHAR(30) DEFAULT NULL");
    if (!isset($cols['nivel_eleccion']))     $db->exec("ALTER TABLE import_log ADD COLUMN nivel_eleccion VARCHAR(20) DEFAULT NULL");
    $hecho = true;
}

/**
 * Deduce la clasificación de una importación a partir de sus preguntas y su cobertura geográfica:
 *  - es_electoral: tiene al menos una pregunta electoral (confianza alta).
 *  - tipo_encuesta (varios): según el objetivo de cada pregunta (voto, imagen, conocimiento, evaluación).
 *  - lista_eleccion: por el cargo que citan las preguntas (alcalde → TERRITORIAL, presidente → PRESIDENCIAL…); null si no hay pista.
 *  - nivel_eleccion: por la cobertura de los leads (1 municipio → municipal, 1 departamento → departamental, más → nacional).
 */
function deducirMetaEncuesta(PDO $db, int $importId): array {
    $st = $db->prepare("SELECT DISTINCT p.id, p.pregunta, p.es_electoral
                        FROM import_log_leads l
                        JOIN import_log_leads_respuestas r ON r.lead_id = l.id_lead
                        JOIN import_log_leads_preguntas p ON p.id = r.pregunta_id
                        WHERE l.import_id = ?");
    $st->execute([$importId]);
    $mapTipo = ['intencion_voto' => 'intencion_voto', 'simpatia_partido' => 'intencion_voto',
                'conocimiento_candidato' => 'conocimiento', 'imagen_candidato' => 'imagen',
                'evaluacion_candidato' => 'evaluacion'];
    $electorales = 0; $total = 0; $objetivos = []; $cargos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total++;
        $low = mb_strtolower($row['pregunta'], 'UTF-8');
        $n   = normBasico($low);
        $an  = analizarElectoral($db, $low);
        if ((int)$row['es_electoral'] === 1 || ($an && $an['confianza'] === 'alta')) $electorales++;
        $obj = $an['objetivo'] ?? objetivoElectoral($n);
        if ($obj && isset($mapTipo[$obj])) $objetivos[$mapTipo[$obj]] = true;
        foreach (['/\balcald/' => 'TERRITORIAL', '/\bgobernad/' => 'TERRITORIAL', '/\bconcej/' => 'TERRITORIAL',
                  '/\bpresiden/' => 'PRESIDENCIAL', '/\bsenad/' => 'SENADO', '/\b(camara|representante)/' => 'CAMARA'] as $re => $lista) {
            if (preg_match($re, $n)) $cargos[$lista] = ($cargos[$lista] ?? 0) + 1;
        }
    }
    $g = $db->prepare("SELECT COUNT(DISTINCT LEFT(id_geo,2)) AS dptos, COUNT(DISTINCT LEFT(id_geo,5)) AS munis
                       FROM import_log_leads WHERE import_id = ? AND id_geo IS NOT NULL");
    $g->execute([$importId]);
    $cob = $g->fetch(PDO::FETCH_ASSOC) ?: ['dptos' => 0, 'munis' => 0];
    $nivel = null;
    if ((int)$cob['munis'] > 0) $nivel = (int)$cob['munis'] === 1 ? 'municipal' : ((int)$cob['dptos'] === 1 ? 'departamental' : 'nacional');
    arsort($cargos);
    $esElec = $electorales > 0;
    return [
        'import_id'      => $importId,
        'es_electoral'   => $esElec,
        'preguntas'      => $total,
        'electorales'    => $electorales,
        'tipo_encuesta'  => ($esElec && $objetivos) ? implode(',', array_keys($objetivos)) : null,
        'lista_eleccion' => ($esElec && $cargos) ? array_key_first($cargos) : null,
        'nivel_eleccion' => $esElec ? $nivel : null,
        'cobertura'      => ['departamentos' => (int)$cob['dptos'], 'municipios' => (int)$cob['munis']],
    ];
}

/** @return int[] ids de dim_tema cuyo keyword matchea el texto de la pregunta */
function clasificarTemaAuto(string $pregLower, array $temaKeywords): array {
    $matches = [];
    foreach ($temaKeywords as $temaId => $kwJson) {
        foreach (json_decode($kwJson, true) as $kw) {
            if (str_contains($pregLower, $kw)) { $matches[] = (int)$temaId; break; }
        }
    }
    return $matches;
}

const STOPWORDS_ES = ['que','de','la','el','los','las','un','una','unos','unas','y','o','a',
    'en','su','sus','se','le','les','es','son','del','al','con','por','para','usted','ha',
    'como','qué','cuál','cuáles','cuál','cómo','tan','más','muy','este','esta','estos','estas',
    'lo','si','no','ya','me','te','nos','sobre','entre','sin'];

function tokenizar(string $texto): array {
    $texto = mb_strtolower($texto, 'UTF-8');
    preg_match_all('/[a-záéíóúñ]{3,}/u', $texto, $m);
    return array_values(array_diff(array_unique($m[0]), STOPWORDS_ES));
}

/**
 * Sugerencia débil cuando el keyword-matching exacto no encontró nada: similitud
 * por tokens compartidos entre la pregunta y (tema + subtema + keywords) de cada
 * fila de dim_tema. Devuelve el top-3 con score > 0 — es una pista, no una
 * clasificación; siempre requiere confirmación manual.
 */
function sugerirTemasDebil(string $pregLower, array $catalogoConKeywords): array {
    $tokensPreg = tokenizar($pregLower);
    if (!$tokensPreg) return [];
    $scored = [];
    foreach ($catalogoConKeywords as $t) {
        $textoTema = $t['tema'] . ' ' . $t['subtema'] . ' ' . implode(' ', json_decode($t['keywords'] ?? '[]', true) ?: []);
        $tokensTema = tokenizar($textoTema);
        if (!$tokensTema) continue;
        $comunes = array_intersect($tokensPreg, $tokensTema);
        if (!$comunes) continue;
        $score = count($comunes) / count(array_unique(array_merge($tokensPreg, $tokensTema)));
        $scored[] = ['tema_id' => (int)$t['id'], 'codigo' => $t['codigo'], 'tema' => $t['tema'],
            'subtema' => $t['subtema'], 'score' => round($score, 3)];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, 3);
}

/**
 * Registra (o reconoce) una pregunta en el catálogo y, si es nueva y no
 * electoral, corre el clasificador automático de tema. No pisa una
 * clasificación manual ya existente.
 */
function registrarPregunta(PDO $db, string $pregRaw, array $temaKeywords): array {
    $pregNorm  = pregNormalize($pregRaw);
    $pregLower = mb_strtolower($pregNorm, 'UTF-8');
    $analisis = analizarElectoral($db, $pregLower);
    $isElectoral = ($analisis && $analisis['confianza'] === 'alta') ? 1 : 0;

    $db->prepare("INSERT IGNORE INTO import_log_leads_preguntas (pregunta, es_electoral) VALUES (?, ?)")
       ->execute([$pregNorm, $isElectoral]);
    $stmt = $db->prepare("SELECT id, es_electoral FROM import_log_leads_preguntas WHERE pregunta = ?");
    $stmt->execute([$pregNorm]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['pregunta_id' => 0, 'pregunta' => $pregNorm, 'es_electoral' => 0, 'temas' => []];

    $pregId = (int)$row['id'];
    $esElectoral = (int)$row['es_electoral'];

    if (!$esElectoral) {
        $yaClasificada = (int)$db->query("SELECT COUNT(*) FROM pregunta_tema WHERE pregunta_id = $pregId")->fetchColumn();
        if ($yaClasificada === 0) {
            $temaIds = clasificarTemaAuto($pregLower, $temaKeywords);
            $stmtPT = $db->prepare(
                "INSERT IGNORE INTO pregunta_tema (pregunta_id, tema_id, origen, pendiente_revision) VALUES (?, ?, 'auto', 1)"
            );
            foreach ($temaIds as $temaId) { $stmtPT->execute([$pregId, $temaId]); }
        }
    }

    $temas = $db->prepare("
        SELECT dt.id AS tema_id, dt.codigo, dt.tema, dt.subtema, pt.pendiente_revision
        FROM pregunta_tema pt JOIN dim_tema dt ON dt.id = pt.tema_id
        WHERE pt.pregunta_id = ?
    ");
    $temas->execute([$pregId]);

    return [
        'pregunta_id'  => $pregId,
        'pregunta'     => $pregNorm,
        'es_electoral' => $esElectoral,
        // componentes detectados (objetivo, candidato). Si la pregunta NO está marcada electoral pero
        // el detector la ve como tal, se ofrece como sugerencia para que el usuario la confirme.
        'electoral'          => $analisis,
        'sugerida_electoral' => (!$esElectoral && $analisis) ? 1 : 0,
        'temas'        => $temas->fetchAll(PDO::FETCH_ASSOC),
    ];
}

// ── Wrappers PHP-nativo para lectura Excel (reemplaza Python) ────────────────

/**
 * Elige el lector según extensión — .xlsx/.xlsm usan el nativo (rápido, sin
 * dependencias); .csv usa el lector CSV nativo; .xls/.ods delegan a
 * PhpSpreadsheet. Ver ExcelReaderPhp.php.
 */
function makeSpreadsheetReader(string $filePath)
{
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx', 'xlsm'], true)) return new PhpXlsxReader($filePath);
    if ($ext === 'csv') return new PhpCsvReader($filePath);
    return new PhpSpreadsheetReader($filePath);
}

/**
 * Retorna info de hojas: [['name'=>'Sheet1','rows'=>int,'cols'=>int], ...]
 */
function xlsxGetSheets(string $filePath): array
{
    $reader = makeSpreadsheetReader($filePath);
    $sheets = $reader->getSheets();
    $reader->close();
    return $sheets;
}

/**
 * Lee filas de una hoja. Retorna ['ok'=>true, 'rows'=>[[...], [...]]]
 */
function xlsxReadRows(string $filePath, string $sheetName, int $maxRows = 0): array
{
    $reader = makeSpreadsheetReader($filePath);
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
    // 3. Patrones de preguntas electorales (el candidato va en el encabezado;
    //    el cruce candidato↔respuesta se resuelve en el paso 3.5 "Contexto electoral")
    $electoralPatterns = [
        'imagen_candidato' => [
            'imagen positiva o negativa', 'imagen negativa o positiva',
            'imagen favorable o desfavorable', 'opinion favorable o desfavorable',
            'opinion positiva o negativa',
        ],
        'conocimiento_candidato' => [
            'conoce o ha escuchado', 'ha escuchado hablar de', 'sabe quien es',
        ],
        'intencion_voto' => [
            'por quien votaria', 'intencion de voto', 'si las elecciones fueran hoy',
        ],
    ];
    foreach ($electoralPatterns as $field => $pats) {
        foreach ($pats as $p) {
            if (str_contains($norm, $p)) return $field;
        }
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

/**
 * ¿El nombre de barrio ($tipo='b') o comuna ($tipo='c') pertenece a un único municipio en c98_geo?
 * Misma coincidencia (LIKE normalizado) que lookupBarrioByName / lookupComunaByName.
 */
function geoNameIsUnique(string $tipo, string $name, \PDO $db): bool {
    if (mb_strlen(trim($name)) < 3) return false;
    $norm = '%' . normalizeGeoStr($name) . '%';
    $sql = $tipo === 'b'
        ? "SELECT COUNT(DISTINCT m.id) FROM c98_geo.dim_barrio b
             JOIN c98_geo.dim_comuna c ON c.id = b.id_comuna
             JOIN c98_geo.dim_zona z ON z.id = c.id_zona
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
           WHERE " . _nsql('b.nombre') . " LIKE ?"
        : "SELECT COUNT(DISTINCT m.id) FROM c98_geo.dim_comuna c
             JOIN c98_geo.dim_zona z ON z.id = c.id_zona
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
           WHERE " . _nsql('c.nombre') . " LIKE ?";
    $st = $db->prepare($sql); $st->execute([$norm]);
    return (int)$st->fetchColumn() === 1;
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

// ── Segmentación de leads (acciones segment_dims / segmentar / segmento_export) ──
// Columnas de import_log_leads permitidas como filtro directo (whitelist estricta).
const SEGMENT_COLS = ['sexo', 'rango_edad', 'estrato', 'nivel_educativo', 'platform', 'afiliacion_politica'];

// Nivel geográfico → [longitud de prefijo de id_geo, subconsulta de resolución sobre c98_geo].
// El id_geo es un código DANE jerárquico VARCHAR(15): 2=depto, 5=municipio, 8=zona, 11=comuna, 15=barrio.
const SEGMENT_GEO_NIVELES = ['region', 'departamento', 'subregion', 'municipio', 'zona', 'comuna', 'barrio'];

/**
 * Construye el WHERE de segmentación sobre `import_log_leads l` a partir del objeto de filtros.
 * Todo va parametrizado (placeholders `?` acumulados en $params, por referencia); los nombres
 * de columna y niveles geográficos se validan contra whitelist — nunca se interpolan del request.
 *
 * $f admite:
 *   sexo, rango_edad, estrato, nivel_educativo, platform, afiliacion_politica : array de valores (IN)
 *   is_organic        : 0 | 1
 *   created_desde     : 'YYYY-MM-DD'
 *   created_hasta     : 'YYYY-MM-DD'  (inclusivo — se suma 1 día)
 *   import_ids        : array de int
 *   geo               : { nivel: <SEGMENT_GEO_NIVELES>, ids: [ ... ] }
 *   preguntas         : [ { pregunta_id: int, valores: [str], op: 'in'|'not_in'|'answered' }, ... ]
 *   preguntas_modo    : 'all' (por defecto: cumple todas) | 'any' (cumple al menos una: unión)
 */
function buildSegmentWhere(array $f, array &$params): string {
    $w = ' WHERE 1=1';

    // Columnas simples (IN)
    foreach (SEGMENT_COLS as $col) {
        if (empty($f[$col]) || !is_array($f[$col])) continue;
        $vals = array_values(array_filter($f[$col], fn($v) => $v !== null && $v !== ''));
        if (!$vals) continue;
        // '__NULL__' = "Sin dato": la columna es NULL o está vacía
        $sinDato = in_array('__NULL__', $vals, true);
        $vals = array_values(array_filter($vals, fn($v) => $v !== '__NULL__'));
        $cond = [];
        if ($vals) {
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $cond[] = "l.`$col` IN ($ph)";
            foreach ($vals as $v) $params[] = $v;
        }
        if ($sinDato) $cond[] = "(l.`$col` IS NULL OR CAST(l.`$col` AS CHAR) = '')";
        $w .= ' AND (' . implode(' OR ', $cond) . ')';
    }

    // is_organic
    if (isset($f['is_organic']) && ($f['is_organic'] === 0 || $f['is_organic'] === 1
        || $f['is_organic'] === '0' || $f['is_organic'] === '1')) {
        $w .= ' AND l.is_organic = ?';
        $params[] = (int)$f['is_organic'];
    }

    // Rango de fechas sobre created_time
    if (!empty($f['created_desde'])) {
        $w .= ' AND l.created_time >= ?';
        $params[] = $f['created_desde'] . ' 00:00:00';
    }
    if (!empty($f['created_hasta'])) {
        $w .= ' AND l.created_time < (? + INTERVAL 1 DAY)';
        $params[] = $f['created_hasta'] . ' 00:00:00';
    }

    // Importaciones
    if (!empty($f['import_ids']) && is_array($f['import_ids'])) {
        $ids = array_values(array_unique(array_map('intval', $f['import_ids'])));
        $ids = array_filter($ids, fn($v) => $v > 0);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $w .= " AND l.import_id IN ($ph)";
            foreach ($ids as $v) $params[] = $v;
        }
    }

    // Geografía
    if (!empty($f['geo']['nivel']) && !empty($f['geo']['ids']) && is_array($f['geo']['ids'])) {
        $nivel = $f['geo']['nivel'];
        if (in_array($nivel, SEGMENT_GEO_NIVELES, true)) {
            $ids = array_values(array_filter(array_map('strval', $f['geo']['ids']), fn($v) => $v !== ''));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $bind = function () use (&$params, $ids) { foreach ($ids as $v) $params[] = $v; };
                switch ($nivel) {
                    case 'region':
                        $w .= " AND LEFT(l.id_geo,2) IN (SELECT id FROM c98_geo.dim_departamento WHERE id_region IN ($ph))";
                        $bind();
                        break;
                    case 'departamento':
                        $w .= " AND LEFT(l.id_geo,2) IN ($ph)";
                        $bind();
                        break;
                    case 'subregion':
                        $w .= " AND LEFT(l.id_geo,5) IN (SELECT id FROM c98_geo.dim_municipio WHERE id_subregion IN ($ph))";
                        $bind();
                        break;
                    case 'municipio':
                        $w .= " AND LEFT(l.id_geo,5) IN ($ph)";
                        $bind();
                        break;
                    case 'zona':
                        $w .= " AND CHAR_LENGTH(l.id_geo) >= 8 AND LEFT(l.id_geo,8) IN ($ph)";
                        $bind();
                        break;
                    case 'comuna':
                        $w .= " AND CHAR_LENGTH(l.id_geo) >= 11 AND LEFT(l.id_geo,11) IN ($ph)";
                        $bind();
                        break;
                    case 'barrio':
                        $w .= " AND l.id_geo IN ($ph)";
                        $bind();
                        break;
                }
            }
        }
    }

    // Condiciones EAV (afinidad de marca / gustos / opinión — preguntas de encuesta)
    if (!empty($f['preguntas']) && is_array($f['preguntas'])) {
        $condSql = []; $condBind = [];   // una entrada por condición; se combinan con Y (por defecto) o con O
        foreach ($f['preguntas'] as $cond) {
            $pid = (int)($cond['pregunta_id'] ?? 0);
            if ($pid <= 0) continue;
            $op = $cond['op'] ?? 'in';
            $valores = (isset($cond['valores']) && is_array($cond['valores']))
                ? array_values(array_filter(array_map('strval', $cond['valores']), fn($v) => $v !== ''))
                : [];
            $neg = ($op === 'not_in');
            $sub = "SELECT 1 FROM import_log_leads_respuestas r WHERE r.lead_id = l.id_lead AND r.pregunta_id = ?";
            $bindSub = [$pid];
            if ($op !== 'answered' && $valores) {
                $ph = implode(',', array_fill(0, count($valores), '?'));
                $sub .= " AND r.respuesta IN ($ph)";
                foreach ($valores as $v) $bindSub[] = $v;
            }
            $condSql[]  = ($neg ? 'NOT EXISTS (' : 'EXISTS (') . $sub . ')';
            $condBind[] = $bindSub;
        }
        // preguntas_modo = 'any' → el lead debe cumplir AL MENOS UNA condición (unión); si no, todas (intersección)
        $union = (($f['preguntas_modo'] ?? 'all') === 'any') && count($condSql) > 1;
        if ($condSql) {
            $w .= $union ? ' AND (' . implode(' OR ', $condSql) . ')' : ' AND ' . implode(' AND ', $condSql);
            foreach ($condBind as $bs) foreach ($bs as $b) $params[] = $b;
        }
    }

    // Filtro por tema/subtema (dim_tema.id) — el lead respondió al menos una pregunta
    // clasificada en alguno de esos temas.
    if (!empty($f['temas']) && is_array($f['temas'])) {
        $temaIds = array_values(array_filter(array_map('intval', $f['temas']), fn($v) => $v > 0));
        if ($temaIds) {
            $ph = implode(',', array_fill(0, count($temaIds), '?'));
            $w .= " AND EXISTS (SELECT 1 FROM import_log_leads_respuestas r
                                JOIN pregunta_tema pt ON pt.pregunta_id = r.pregunta_id
                                WHERE r.lead_id = l.id_lead AND pt.tema_id IN ($ph))";
            foreach ($temaIds as $v) $params[] = $v;
        }
    }

    return $w;
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
    // Municipio elegido por el usuario en el wizard (id DANE de 5 chars): no depende del
    // nombre, así que resuelve bien aunque existan municipios homónimos en otros deptos.
    $mpioId = trim((string)($lead['_mpio_id'] ?? ''));

    // Normalizar Bogotá localidades
    if ($mpio !== '' && stripos($mpio, 'LOCALIDAD') !== false) {
        $mpio = 'Bogota';
    }

    $mNorm = normalizeGeoStr($mpio);
    $hasMpio = ($mpio !== '' || $mpioId !== '');
    // Condición/params del municipio: por id si lo tenemos, si no por nombre/abreviatura.
    if ($mpioId !== '') {
        $mCond   = "m.id = ?";
        $mParams = [$mpioId];
    } else {
        $mCond   = "("._nsql('m.nombre')." = ? OR "._nsql('m.abreviatura')." = ?)";
        $mParams = [$mNorm, $mNorm];
    }

    // P1 – Barrio: busca barrio en el municipio correcto
    if ($barrio !== '' && $hasMpio) {
        $st = $db->prepare(
            "SELECT b.id FROM c98_geo.dim_barrio b
             JOIN c98_geo.dim_comuna  c ON c.id = b.id_comuna
             JOIN c98_geo.dim_zona    z ON z.id = c.id_zona
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
             WHERE "._nsql('b.nombre')." = ?
               AND $mCond
             LIMIT 1"
        );
        $st->execute(array_merge([normalizeGeoStr($barrio)], $mParams));
        $id = $st->fetchColumn();
        if ($id !== false) return (string)$id;
    }

    // P2 – Comuna
    if ($comuna !== '' && $hasMpio) {
        $st = $db->prepare(
            "SELECT c.id FROM c98_geo.dim_comuna c
             JOIN c98_geo.dim_zona      z ON z.id = c.id_zona
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
             WHERE "._nsql('c.nombre')." = ?
               AND $mCond
             LIMIT 1"
        );
        $st->execute(array_merge([normalizeGeoStr($comuna)], $mParams));
        $id = $st->fetchColumn();
        if ($id !== false) return (string)$id;
    }

    // P2b – Zona
    if ($zona !== '' && $hasMpio) {
        $st = $db->prepare(
            "SELECT z.id FROM c98_geo.dim_zona z
             JOIN c98_geo.dim_municipio m ON m.id = z.id_municipio
             WHERE "._nsql('z.nombre')." = ?
               AND $mCond
             LIMIT 1"
        );
        $st->execute(array_merge([normalizeGeoStr($zona)], $mParams));
        $id = $st->fetchColumn();
        if ($id !== false) return (string)$id;
    }

    // P3 – Municipio fallback (por id si el usuario lo eligió; si no, por nombre)
    if ($mpioId !== '') {
        $st = $db->prepare("SELECT id FROM c98_geo.dim_municipio WHERE id = ? LIMIT 1");
        $st->execute([$mpioId]);
        $id = $st->fetchColumn();
        if ($id !== false) return (string)$id;
    }
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
    // Solo si el nombre del barrio pertenece a UN único municipio: nombres como "El Porvenir" o
    // "San Antonio" existen en muchas ciudades y tomar la primera coincidencia ubica el lead en otra ciudad.
    if (!empty($lead['barrio']) && empty($lead['municipio'])) {
        $geoB = geoNameIsUnique('b', $lead['barrio'], $db) ? lookupBarrioByName($lead['barrio'], $db) : null;
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
        $geoC = geoNameIsUnique('c', $lead['comuna_localidad'], $db) ? lookupComunaByName($lead['comuna_localidad'], null, $db) : null;
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
    if (!in_array($ext, ['xlsx','xls','ods','csv'])) errOut('Formato no soportado. Use .xlsx, .xls, .csv u .ods');
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
        // .xls/.csv/.ods dependen de PhpSpreadsheet (vendor/) — .xlsx no lo necesita
        $hasPhpSpreadsheet = file_exists(__DIR__ . '/vendor/autoload.php');

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
            'phpspreadsheet' => $hasPhpSpreadsheet ? 'ready (.xls/.csv/.ods)' : 'faltante — correr composer install',
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
                " . ($importId ? "WHERE id = $importId" : "") . "
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
                " . ($importId ? "WHERE il.id = $importId" : "") . "
                ORDER BY il.id DESC LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 7. Temas/subtemas — drill-down igual que geo (Dominio/Tema › Subtema › Pregunta),
            // ver app/docs/taxonomia_temas_subtemas.md. Cuenta RESPUESTAS (no preguntas), para
            // reflejar cuánta gente contestó algo de cada tema, no cuántas preguntas hay.
            $joinLeadTemas = $importId ? "JOIN import_log_leads l ON l.id_lead = r.lead_id" : "";
            $whereTemas    = $importId ? "WHERE l.import_id = $importId" : "";
            $temas = [];
            $temas['tema'] = array_map(fn($r) => ['val' => $r['val'], 'cnt' => (int)$r['cnt']], $db->query("
                SELECT dt.tema AS val, COUNT(r.id) AS cnt
                FROM dim_tema dt
                JOIN pregunta_tema pt ON pt.tema_id = dt.id
                JOIN import_log_leads_respuestas r ON r.pregunta_id = pt.pregunta_id
                $joinLeadTemas
                $whereTemas
                GROUP BY dt.tema ORDER BY cnt DESC
            ")->fetchAll(PDO::FETCH_ASSOC));
            $temas['subtema'] = array_map(fn($r) => ['val' => $r['val'], 'cnt' => (int)$r['cnt']], $db->query("
                SELECT CONCAT(dt.subtema, '|||', dt.tema) AS val, COUNT(r.id) AS cnt
                FROM dim_tema dt
                JOIN pregunta_tema pt ON pt.tema_id = dt.id
                JOIN import_log_leads_respuestas r ON r.pregunta_id = pt.pregunta_id
                $joinLeadTemas
                $whereTemas
                GROUP BY dt.id ORDER BY cnt DESC
            ")->fetchAll(PDO::FETCH_ASSOC));
            $leadFilterSub = $importId
                ? "AND r.lead_id IN (SELECT id_lead FROM import_log_leads WHERE import_id = $importId)"
                : "";
            $temas['preguntas'] = $db->query("
                SELECT p.id AS pregunta_id, p.pregunta, dt.tema, dt.subtema, dt.codigo,
                       COUNT(r.id) AS n_respuestas
                FROM dim_tema dt
                JOIN pregunta_tema pt ON pt.tema_id = dt.id
                JOIN import_log_leads_preguntas p ON p.id = pt.pregunta_id
                LEFT JOIN import_log_leads_respuestas r ON r.pregunta_id = p.id $leadFilterSub
                GROUP BY p.id, dt.id
                " . ($importId ? "HAVING n_respuestas > 0" : "") . "
                ORDER BY dt.codigo, n_respuestas DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($temas['preguntas'] as &$pr) { $pr['n_respuestas'] = (int)$pr['n_respuestas']; $pr['pregunta_id'] = (int)$pr['pregunta_id']; }
            unset($pr);

            jsonOut([
                'ok'          => true,
                'total'       => $total,
                'kpi'         => $kpi,
                'cobertura'   => $cobertura,
                'dist'        => $dist,
                'geo'         => $geo,
                'temas'       => $temas,
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
        // Municipio por defecto por hoja: {sheetName: {id:'05001', nombre:'Medellín', departamento:'Antioquia'}}.
        // Se usa cuando la hoja mapea una ubicación inferior a municipio (zona/comuna/barrio) sin
        // columna de municipio: sin él no se puede resolver id_geo ni mostrar municipio al descargar.
        $sheetGeoDefaults = json_decode($_POST['sheet_geo_defaults'] ?? '{}', true) ?? [];
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
            // Clasificación de las encuestas electorales (la usa la pantalla Electoral para filtrar)
            "ALTER TABLE import_log ADD COLUMN tipo_encuesta   VARCHAR(120) DEFAULT NULL",
            "ALTER TABLE import_log ADD COLUMN lista_eleccion  VARCHAR(30)  DEFAULT NULL",
            "ALTER TABLE import_log ADD COLUMN nivel_eleccion  VARCHAR(20)  DEFAULT NULL",
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
        // es_electoral se detecta con analizarElectoral() (palabras clave + candidatos del catálogo)
        $stmtInsPreg = $db->prepare(
            "INSERT IGNORE INTO import_log_leads_preguntas (pregunta, es_electoral) VALUES (?, ?)"
        );
        $stmtGetPreg = $db->prepare(
            "SELECT id FROM import_log_leads_preguntas WHERE pregunta = ?"
        );
        // Clasificación automática de tema/subtema (dominio Social/Interno) para preguntas
        // nuevas — mismo patrón que es_electoral, ver app/docs/taxonomia_temas_subtemas.md §7.4.
        // Se inserta como pendiente_revision=1: nunca se trata como definitiva sin revisión humana.
        $temaKeywords = $db->query("SELECT id, keywords FROM dim_tema")->fetchAll(PDO::FETCH_KEY_PAIR);
        $stmtInsPregTema = $db->prepare(
            "INSERT IGNORE INTO pregunta_tema (pregunta_id, tema_id, origen, pendiente_revision) VALUES (?, ?, 'auto', 1)"
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
                // Guarda la clasificación de la encuesta electoral (tipo, lista de elección, nivel)
                if ($electoralCtx && $importId) {
                    $db->prepare("UPDATE import_log SET tipo_encuesta = ?, lista_eleccion = ?, nivel_eleccion = ? WHERE id = ?")
                       ->execute([
                           $electoralCtx['tipo_encuesta'] ?? null,
                           !empty($electoralCtx['tipo_eleccion_sel']) ? strtoupper($electoralCtx['tipo_eleccion_sel']) : null,
                           $electoralCtx['nivel'] ?? null,
                           $importId,
                       ]);
                }

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

                    // ── Municipio por defecto de la hoja (elegido en el wizard) ──
                    // Solo rellena filas sin municipio propio: si el Excel trae la columna, esa manda.
                    $geoDef = $sheetGeoDefaults[$sheet] ?? null;
                    if (is_array($geoDef) && !empty($geoDef['id']) && empty($lead['municipio'])) {
                        $lead['municipio'] = (string)($geoDef['nombre'] ?? '');
                        $lead['_mpio_id']  = (string)$geoDef['id'];
                        if (empty($lead['geo_departamento']) && !empty($geoDef['departamento'])) {
                            $lead['geo_departamento'] = (string)$geoDef['departamento'];
                        }
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
                                $an = analizarElectoral($db, $pregLower);
                                $isElectoral = ($an && $an['confianza'] === 'alta') ? 1 : 0;
                                $stmtInsPreg->execute([$pregNorm, $isElectoral]);
                                $esPreguntaNueva = $stmtInsPreg->rowCount() > 0;
                                $stmtGetPreg->execute([$pregNorm]);
                                $pregIdCache[$pregNorm] = (int)($stmtGetPreg->fetchColumn() ?: 0);
                                if ($esPreguntaNueva && !$isElectoral && $pregIdCache[$pregNorm]) {
                                    foreach ($temaKeywords as $temaId => $kwJson) {
                                        foreach (json_decode($kwJson, true) as $kw) {
                                            if (str_contains($pregLower, $kw)) {
                                                $stmtInsPregTema->execute([$pregIdCache[$pregNorm], $temaId]);
                                                break;
                                            }
                                        }
                                    }
                                }
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

    // ── Agregar partido rápido ──────────────────────────────────────────
    if ($action === 'add_partido') {
        $db = getDB();
        $nombre = trim($_POST['nombre'] ?? '');
        $sigla  = trim($_POST['sigla']  ?? '') ?: null;
        if (!$nombre) errOut('Nombre del partido requerido.');

        // Detectar columnas reales de dim_partido
        $ptCols = $db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dim_partido'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $ptName    = in_array('nombre_partido', $ptCols) ? 'nombre_partido'
                   : (in_array('partido', $ptCols) ? 'partido' : 'nombre_partido');
        $hasActivo = in_array('activo', $ptCols);
        $hasSigla  = in_array('sigla',  $ptCols);

        try {
            // ¿Ya existe? (case / trim insensitive)
            $stmt = $db->prepare(
                "SELECT id_partido, `$ptName` AS nombre FROM dim_partido
                 WHERE UPPER(TRIM(`$ptName`)) = UPPER(TRIM(?)) LIMIT 1"
            );
            $stmt->execute([$nombre]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                jsonOut(['ok' => true, 'id' => (int)$row['id_partido'],
                         'nombre' => $row['nombre'], 'existing' => true]);
            }

            $cols = ["`$ptName`"]; $ph = ['?']; $vals = [$nombre];
            if ($hasSigla)  { $cols[] = 'sigla';  $ph[] = '?'; $vals[] = $sigla; }
            if ($hasActivo) { $cols[] = 'activo'; $ph[] = '?'; $vals[] = 1; }
            $db->prepare(
                "INSERT INTO dim_partido (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")"
            )->execute($vals);

            jsonOut(['ok' => true, 'id' => (int)$db->lastInsertId(), 'nombre' => $nombre]);
        } catch (\Throwable $e) {
            errOut($e->getMessage());
        }
    }

    // ── REGISTRAR_PREGUNTAS ───────────────────────────────────
    // Paso "Temas" del wizard: recibe los headers EAV detectados en ESTA
    // importación (antes de confirmar), los registra en el catálogo si son
    // nuevos, y devuelve su clasificación (existente o auto-sugerida) para
    // que el usuario la confirme/corrija antes de importar los leads.
    if ($action === 'registrar_preguntas') {
        $db = getDB();
        $headers = json_decode($_POST['headers'] ?? '[]', true);
        if (!is_array($headers)) errOut('headers debe ser un array JSON.');

        $temaKeywords = $db->query("SELECT id, keywords FROM dim_tema")->fetchAll(PDO::FETCH_KEY_PAIR);
        $catalogo = $db->query("SELECT id, dominio, tema, subtema, codigo FROM dim_tema ORDER BY codigo")->fetchAll();

        $resultado = [];
        foreach (array_unique(array_filter(array_map('strval', $headers))) as $h) {
            $resultado[] = registrarPregunta($db, $h, $temaKeywords);
        }
        jsonOut(['ok' => true, 'preguntas' => $resultado, 'catalogo' => $catalogo]);
    }

    // ── ENCUESTAS_ELECTORALES ─────────────────────────────────
    // Encuestas electorales importadas (import_log.tipo='electoral') + opciones de los selectores de
    // la pantalla Electoral. Filtros opcionales: tipo_encuesta, lista_eleccion, nivel_eleccion ('' = todas).
    if ($action === 'encuestas_electorales') {
        $db = getDB();
        ensureEncuestaCols($db);
        $where = "WHERE tipo = 'electoral'"; $params = [];
        foreach (['tipo_encuesta', 'lista_eleccion', 'nivel_eleccion'] as $f) {
            $v = trim((string)($_GET[$f] ?? ''));
            if ($v === '__sin__')                { $where .= " AND $f IS NULL"; }
            elseif ($v !== '' && $f === 'tipo_encuesta') { $where .= " AND FIND_IN_SET(?, tipo_encuesta) > 0"; $params[] = $v; }
            elseif ($v !== '')                   { $where .= " AND $f = ?"; $params[] = $v; }
        }
        $st = $db->prepare("SELECT id, archivo, hoja, importado_en, filas_ins, tipo_encuesta, lista_eleccion, nivel_eleccion
                            FROM import_log $where ORDER BY id DESC");
        $st->execute($params);
        $listas = $db->query("SELECT DISTINCT tipo_eleccion FROM dim_participacion ORDER BY tipo_eleccion")->fetchAll(PDO::FETCH_COLUMN);
        jsonOut([
            'ok' => true,
            'encuestas' => $st->fetchAll(PDO::FETCH_ASSOC),
            'opciones'  => [
                'tipos' => [
                    ['v' => 'intencion_voto', 'l' => 'Intención de voto'], ['v' => 'imagen', 'l' => 'Imagen / Reputación'],
                    ['v' => 'conocimiento', 'l' => 'Conocimiento de candidatos'], ['v' => 'evaluacion', 'l' => 'Evaluación de gestión'],
                    ['v' => 'exit_poll', 'l' => 'Exit poll'], ['v' => 'omnibus', 'l' => 'Omnibus / General'],
                ],
                'listas'  => array_map(fn($l) => ['v' => $l, 'l' => $l === 'CAMARA' ? 'Cámara' : ucfirst(mb_strtolower($l, 'UTF-8'))], $listas),
                'niveles' => [
                    ['v' => 'nacional', 'l' => 'Nacional'], ['v' => 'departamental', 'l' => 'Departamental'],
                    ['v' => 'municipal', 'l' => 'Municipal'], ['v' => 'local', 'l' => 'Local'],
                ],
            ],
        ]);
    }

    // ── ELECTORAL_RESUMEN ─────────────────────────────────────
    // Resumen de UNA encuesta electoral para la pantalla Electoral: ficha, cobertura y, por cada pregunta
    // electoral (intención de voto, imagen, conocimiento), la distribución de respuestas, opcionalmente
    // cortada por sexo / rango_edad / estrato y filtrada por segmento y ubicación (mismos filtros que 'segmentar').
    if ($action === 'electoral_resumen') {
        $db = getDB();
        $importId = (int)($_POST['import_id'] ?? $_GET['import_id'] ?? 0);
        if ($importId <= 0) errOut('import_id requerido.');
        $filtros = json_decode($_POST['filtros'] ?? '{}', true) ?: [];
        $filtros['import_ids'] = [$importId];
        $corte = in_array(($_POST['corte'] ?? ''), ['sexo', 'rango_edad', 'estrato'], true) ? $_POST['corte'] : null;

        $info = $db->prepare("SELECT id, archivo, hoja, importado_en, filas_ins, tipo_encuesta, lista_eleccion, nivel_eleccion
                              FROM import_log WHERE id = ?");
        $info->execute([$importId]);
        $ficha = $info->fetch(PDO::FETCH_ASSOC);
        if (!$ficha) errOut('Encuesta no encontrada.', 404);

        $params = [];
        $where = buildSegmentWhere($filtros, $params);
        $st = $db->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT LEFT(l.id_geo,2)) AS dptos, COUNT(DISTINCT LEFT(l.id_geo,5)) AS munis
                            FROM import_log_leads l $where");
        $st->execute($params);
        $tot = $st->fetch(PDO::FETCH_ASSOC);

        $grp = $corte ? "COALESCE(NULLIF(l.`$corte`, ''), 'Sin dato')" : "''";
        $st = $db->prepare("SELECT r.pregunta_id, $grp AS grupo, r.respuesta, COUNT(*) AS n
                            FROM import_log_leads l
                            JOIN import_log_leads_respuestas r ON r.lead_id = l.id_lead
                            $where GROUP BY r.pregunta_id, grupo, r.respuesta");
        $st->execute($params);
        $porPregunta = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porPregunta[(int)$r['pregunta_id']][] = ['grupo' => $r['grupo'], 'respuesta' => (string)$r['respuesta'], 'n' => (int)$r['n']];
        }

        $preguntas = [];
        if ($porPregunta) {
            $ids = implode(',', array_map('intval', array_keys($porPregunta)));
            $rows = $db->query("SELECT id, pregunta, es_electoral FROM import_log_leads_preguntas WHERE id IN ($ids) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $p) {
                $low = mb_strtolower($p['pregunta'], 'UTF-8');
                $n   = normBasico($low);
                $an  = analizarElectoral($db, $low);
                $obj = $an['objetivo'] ?? objetivoElectoral($n);
                $tipo = match ($obj) {
                    'intencion_voto', 'simpatia_partido', 'participacion_electoral' => 'voto',
                    'imagen_candidato', 'evaluacion_candidato'                       => 'imagen',
                    'conocimiento_candidato'                                         => 'conocimiento',
                    default => null,
                };
                if (!$tipo && (int)$p['es_electoral'] === 1) $tipo = 'voto';
                if (!$tipo) continue;   // pregunta no electoral: no entra en esta pantalla
                $persona = null;
                if ($tipo !== 'voto' && preg_match('/(?:imagen[^?]*?\bde|conoce a|conoces a|opini[oó]n sobre|sobre)\s+(.+?)\s*\??\s*$/iu', $p['pregunta'], $m)) {
                    $persona = trim($m[1]);
                }
                $preguntas[] = ['pregunta_id' => (int)$p['id'], 'pregunta' => $p['pregunta'], 'tipo' => $tipo,
                                'persona' => $persona, 'datos' => $porPregunta[(int)$p['id']]];
            }
        }
        jsonOut(['ok' => true, 'ficha' => $ficha, 'total' => (int)$tot['total'],
                 'cobertura' => ['departamentos' => (int)$tot['dptos'], 'municipios' => (int)$tot['munis']],
                 'corte' => $corte, 'preguntas' => $preguntas]);
    }

    // ── ENCUESTAS_AUTOCLASIFICAR ──────────────────────────────
    // Deduce tipo(s), lista de elección y nivel de cada importación de tipo 'electoral' a partir de sus
    // preguntas y cobertura. Por defecto SOLO SIMULA (GET); con POST aplicar=1 guarda:
    //  - lo que no es electoral (ninguna pregunta electoral) pasa a tipo 'leads';
    //  - en las electorales solo se rellenan campos vacíos (nunca pisa una clasificación manual).
    if ($action === 'encuestas_autoclasificar') {
        $db = getDB();
        ensureEncuestaCols($db);
        $aplicar = ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['aplicar'] ?? '0') === '1');
        $filas = $db->query("SELECT id, archivo, tipo_encuesta, lista_eleccion, nivel_eleccion FROM import_log
                             WHERE tipo = 'electoral' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($filas as $f) {
            $d = deducirMetaEncuesta($db, (int)$f['id']);
            $accion = $d['es_electoral'] ? 'clasificar' : 'pasar_a_leads';
            if ($aplicar) {
                if (!$d['es_electoral']) {
                    $db->prepare("UPDATE import_log SET tipo = 'leads' WHERE id = ?")->execute([$f['id']]);
                } else {
                    $db->prepare("UPDATE import_log SET tipo_encuesta = COALESCE(tipo_encuesta, ?),
                                  lista_eleccion = COALESCE(lista_eleccion, ?), nivel_eleccion = COALESCE(nivel_eleccion, ?)
                                  WHERE id = ?")->execute([$d['tipo_encuesta'], $d['lista_eleccion'], $d['nivel_eleccion'], $f['id']]);
                }
            }
            $out[] = ['archivo' => $f['archivo'], 'accion' => $accion, 'actual' => [
                'tipo_encuesta' => $f['tipo_encuesta'], 'lista_eleccion' => $f['lista_eleccion'], 'nivel_eleccion' => $f['nivel_eleccion']]] + $d;
        }
        jsonOut(['ok' => true, 'aplicado' => $aplicar, 'encuestas' => $out]);
    }

    // ── ENCUESTA_META_SET ─────────────────────────────────────
    // Asigna a mano tipo / lista de elección / nivel a una encuesta electoral ya importada.
    if ($action === 'encuesta_meta_set') {
        $db = getDB();
        $id = (int)($_POST['import_id'] ?? 0);
        if ($id <= 0) errOut('import_id requerido.');
        $val = fn($k) => (($v = trim((string)($_POST[$k] ?? ''))) === '') ? null : $v;
        $db->prepare("UPDATE import_log SET tipo_encuesta = ?, lista_eleccion = ?, nivel_eleccion = ? WHERE id = ? AND tipo = 'electoral'")
           ->execute([$val('tipo_encuesta'), $val('lista_eleccion'), $val('nivel_eleccion'), $id]);
        jsonOut(['ok' => true, 'import_id' => $id]);
    }

    // ── PREGUNTA_ELECTORAL_SET ────────────────────────────────
    // Confirma o corrige a mano si una pregunta del catálogo es electoral (paso "Temas" del wizard).
    if ($action === 'pregunta_electoral_set') {
        $db = getDB();
        $id = (int)($_POST['pregunta_id'] ?? 0);
        $val = ((int)($_POST['es_electoral'] ?? 0)) ? 1 : 0;
        if ($id <= 0) errOut('pregunta_id requerido.');
        $st = $db->prepare("UPDATE import_log_leads_preguntas SET es_electoral = ? WHERE id = ?");
        $st->execute([$val, $id]);
        jsonOut(['ok' => true, 'pregunta_id' => $id, 'es_electoral' => $val]);
    }

    // ── TEMAS_PENDIENTES ──────────────────────────────────────
    // Cola de revisión de la taxonomía tema/subtema (ver app/docs/taxonomia_temas_subtemas.md §7.5)
    if ($action === 'temas_pendientes') {
        $db = getDB();
        $catalogo = $db->query("
            SELECT id, dominio, tema, subtema, codigo
            FROM dim_tema
            ORDER BY codigo
        ")->fetchAll();
        $catalogoConKeywords = $db->query("SELECT id, tema, subtema, codigo, keywords FROM dim_tema")->fetchAll();

        // Sugeridas por el clasificador automático, aún no confirmadas
        $sugeridas = $db->query("
            SELECT p.id AS pregunta_id, p.pregunta, pt.tema_id, dt.codigo, dt.tema, dt.subtema
            FROM pregunta_tema pt
            JOIN import_log_leads_preguntas p ON p.id = pt.pregunta_id
            JOIN dim_tema dt ON dt.id = pt.tema_id
            WHERE pt.pendiente_revision = 1
            ORDER BY p.id
        ")->fetchAll();

        // Sin ninguna clasificación todavía (ni auto ni manual) y no electorales
        $sinTema = $db->query("
            SELECT p.id, p.pregunta
            FROM import_log_leads_preguntas p
            WHERE p.es_electoral = 0
              AND NOT EXISTS (SELECT 1 FROM pregunta_tema pt WHERE pt.pregunta_id = p.id)
            ORDER BY p.id
        ")->fetchAll();
        // Sin match exacto de keywords: se les calcula una sugerencia débil por
        // similitud de tokens, para no dejar al analista sin ningún punto de partida.
        foreach ($sinTema as &$row) {
            $row['sugerencias_debiles'] = sugerirTemasDebil(mb_strtolower($row['pregunta'], 'UTF-8'), $catalogoConKeywords);
        }
        unset($row);

        jsonOut(['ok' => true, 'catalogo' => $catalogo, 'sugeridas' => $sugeridas, 'sin_tema' => $sinTema]);
    }

    // ── RESPUESTAS_MUESTRA ────────────────────────────────────
    // Distribución real de respuestas de una pregunta — contexto para clasificarla.
    // ── BUSCAR_RESPUESTAS ─────────────────────────────────────
    // Preguntas cuyo VALOR DE RESPUESTA contiene el texto buscado (p. ej. "manuel villa" → la pregunta de
    // "¿por cuál de ellos votaría…?", cuya pregunta no nombra al candidato pero sus respuestas sí).
    if ($action === 'buscar_respuestas') {
        $db = getDB();
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 3) jsonOut(['ok' => true, 'hits' => []]);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $st = $db->prepare("SELECT r.pregunta_id, r.respuesta, COUNT(*) AS n
                            FROM import_log_leads_respuestas r
                            WHERE r.respuesta LIKE ?
                            GROUP BY r.pregunta_id, r.respuesta
                            ORDER BY n DESC LIMIT 60");
        $st->execute([$like]);
        $hits = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $hits[(string)$r['pregunta_id']][] = ['respuesta' => $r['respuesta'], 'n' => (int)$r['n']];
        }
        jsonOut(['ok' => true, 'hits' => $hits]);
    }

    // ── SEGMENTO_PREGUNTAS ────────────────────────────────────
    // Preguntas de encuesta respondidas por los leads del SEGMENTO ACTUAL (mismos filtros que 'segmentar'),
    // con cuántos leads del segmento respondieron cada una. Alimenta el modal "Condición de encuesta".
    if ($action === 'segmento_preguntas') {
        $db = getDB();
        $filtros = json_decode($_POST['filtros'] ?? '{}', true) ?: [];
        $params = [];
        $where = buildSegmentWhere($filtros, $params);
        $st = $db->prepare("SELECT r.pregunta_id, COUNT(DISTINCT r.lead_id) AS n
                            FROM import_log_leads l
                            JOIN import_log_leads_respuestas r ON r.lead_id = l.id_lead
                            $where GROUP BY r.pregunta_id");
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(string)$r['pregunta_id']] = (int)$r['n']; }
        jsonOut(['ok' => true, 'conteos' => $out]);
    }

    // ── SEGMENTO_RESPUESTAS ───────────────────────────────────
    // Distribución de respuestas de UNA pregunta dentro del segmento actual.
    if ($action === 'segmento_respuestas') {
        $db = getDB();
        $filtros = json_decode($_POST['filtros'] ?? '{}', true) ?: [];
        $preguntaId = (int)($_POST['pregunta_id'] ?? 0);
        if (!$preguntaId) errOut('Falta pregunta_id.');
        $params = [];
        $where = buildSegmentWhere($filtros, $params);
        $st = $db->prepare("SELECT r.respuesta, COUNT(*) AS n
                            FROM import_log_leads l
                            JOIN import_log_leads_respuestas r ON r.lead_id = l.id_lead
                            $where AND r.pregunta_id = ?
                            GROUP BY r.respuesta ORDER BY n DESC LIMIT 50");
        $st->execute([...$params, $preguntaId]);
        jsonOut(['ok' => true, 'pregunta_id' => $preguntaId, 'respuestas' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'respuestas_muestra') {
        $db = getDB();
        $preguntaId = (int)($_GET['pregunta_id'] ?? 0);
        $importId   = isset($_GET['import_id']) ? (int)$_GET['import_id'] : null;
        if (!$preguntaId) errOut('Falta pregunta_id.');
        $importFilter = $importId
            ? "AND r.lead_id IN (SELECT id_lead FROM import_log_leads WHERE import_id = $importId)"
            : "";
        $stmt = $db->prepare("
            SELECT r.respuesta, COUNT(*) AS n
            FROM import_log_leads_respuestas r
            WHERE r.pregunta_id = ? $importFilter
            GROUP BY r.respuesta
            ORDER BY n DESC
            LIMIT 20
        ");
        $stmt->execute([$preguntaId]);
        $filas = $stmt->fetchAll();
        $totalStmt = $db->prepare("SELECT COUNT(*) FROM import_log_leads_respuestas r WHERE r.pregunta_id = ? $importFilter");
        $totalStmt->execute([$preguntaId]);
        $total = (int)$totalStmt->fetchColumn();
        foreach ($filas as &$f) { $f['pct'] = $total ? round(100 * $f['n'] / $total, 1) : 0; }
        unset($f);
        jsonOut(['ok' => true, 'pregunta_id' => $preguntaId, 'total' => $total, 'respuestas' => $filas]);
    }

    // ── CREAR_TEMA ────────────────────────────────────────────
    // Crea un tema/subtema nuevo en dim_tema cuando ninguno de los existentes aplica.
    if ($action === 'crear_tema') {
        $db = getDB();
        $dominio = trim($_POST['dominio'] ?? '');
        $tema    = trim($_POST['tema'] ?? '');
        $subtema = trim($_POST['subtema'] ?? '');
        $keywordsRaw = trim($_POST['keywords'] ?? '');
        if (!in_array($dominio, ['Social', 'Interno'], true)) errOut('Dominio inválido.');
        if ($tema === '' || $subtema === '') errOut('Tema y subtema son requeridos.');

        $keywords = array_values(array_filter(array_map('trim', explode(',', $keywordsRaw))));
        if (!$keywords) $keywords = [mb_strtolower($subtema, 'UTF-8')];

        // Código jerárquico: reutiliza el prefijo del tema si ya existe, si no crea uno nuevo
        $prefijoStmt = $db->prepare("SELECT codigo FROM dim_tema WHERE tema = ? ORDER BY codigo DESC LIMIT 1");
        $prefijoStmt->execute([$tema]);
        $ultimoCodigo = $prefijoStmt->fetchColumn();
        if ($ultimoCodigo) {
            [$base, $n] = explode('.', $ultimoCodigo);
            $codigo = $base . '.' . ((int)$n + 1);
        } else {
            $prefijoBase = $dominio === 'Interno' ? 'INT' : 'SOC';
            $maxBase = (int)$db->query("
                SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(codigo,'-',2),'-',-1) AS UNSIGNED))
                FROM dim_tema WHERE codigo LIKE '{$prefijoBase}-%'
            ")->fetchColumn();
            $codigo = $prefijoBase . '-' . ($maxBase + 1) . '.1';
        }

        try {
            $db->prepare("INSERT INTO dim_tema (dominio, tema, subtema, codigo, keywords) VALUES (?,?,?,?,?)")
               ->execute([$dominio, $tema, $subtema, $codigo, json_encode($keywords, JSON_UNESCAPED_UNICODE)]);
            $newId = (int)$db->lastInsertId();
            jsonOut(['ok' => true, 'id' => $newId, 'dominio' => $dominio, 'tema' => $tema, 'subtema' => $subtema, 'codigo' => $codigo]);
        } catch (\Throwable $e) {
            errOut('No se pudo crear (¿ya existe ese tema+subtema?): ' . $e->getMessage());
        }
    }

    // ── TEMAS_GUARDAR ─────────────────────────────────────────
    // Confirma/corrige el tema(s) de una pregunta. Reemplaza sus filas en pregunta_tema
    // por la selección final del analista, marcadas origen='manual', pendiente_revision=0.
    if ($action === 'temas_guardar') {
        $db = getDB();
        $preguntaId = (int)($_POST['pregunta_id'] ?? 0);
        $temaIdsRaw = $_POST['tema_ids'] ?? '';
        $temaIds = array_filter(array_map('intval', explode(',', (string)$temaIdsRaw)));
        if (!$preguntaId) errOut('Falta pregunta_id.');

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM pregunta_tema WHERE pregunta_id = ?")->execute([$preguntaId]);
            $stmt = $db->prepare(
                "INSERT INTO pregunta_tema (pregunta_id, tema_id, origen, pendiente_revision) VALUES (?, ?, 'manual', 0)"
            );
            foreach (array_unique($temaIds) as $temaId) {
                $stmt->execute([$preguntaId, $temaId]);
            }
            $db->commit();
            jsonOut(['ok' => true, 'pregunta_id' => $preguntaId, 'tema_ids' => array_values(array_unique($temaIds))]);
        } catch (\Throwable $e) {
            $db->rollBack();
            errOut($e->getMessage());
        }
    }

    // ── SEGMENT_DIMS ──────────────────────────────────────────
    // Cataloga los valores disponibles para poblar el constructor de consultas
    // de leads_segmentacion.html (demografía, política, geografía, preguntas).
    if ($action === 'segment_dims') {
        $db = getDB();
        $out = ['ok' => true];

        // Valores categóricos simples (con conteo)
        $catQueries = [
            'sexo'                => 'SELECT sexo AS val, COUNT(*) AS cnt FROM import_log_leads WHERE sexo IS NOT NULL AND sexo <> "" GROUP BY sexo ORDER BY cnt DESC',
            'rango_edad'          => 'SELECT rango_edad AS val, COUNT(*) AS cnt FROM import_log_leads WHERE rango_edad IS NOT NULL AND rango_edad <> "" GROUP BY rango_edad ORDER BY FIELD(rango_edad,"18a24","25a34","35a44","45a65+","18-24","25-34","35-44","45-65+") ASC',
            'estrato'             => 'SELECT estrato AS val, COUNT(*) AS cnt FROM import_log_leads WHERE estrato IS NOT NULL GROUP BY estrato ORDER BY estrato ASC',
            'platform'            => 'SELECT platform AS val, COUNT(*) AS cnt FROM import_log_leads WHERE platform IS NOT NULL AND platform <> "" GROUP BY platform ORDER BY cnt DESC',
            'afiliacion_politica' => 'SELECT afiliacion_politica AS val, COUNT(*) AS cnt FROM import_log_leads WHERE afiliacion_politica IS NOT NULL AND afiliacion_politica <> "" GROUP BY afiliacion_politica ORDER BY cnt DESC LIMIT 40',
            'nivel_educativo'     => 'SELECT nivel_educativo AS val, COUNT(*) AS cnt FROM import_log_leads WHERE nivel_educativo IS NOT NULL AND nivel_educativo <> "" GROUP BY nivel_educativo ORDER BY cnt DESC LIMIT 40',
        ];
        foreach ($catQueries as $k => $sql) {
            $out[$k] = array_map(
                fn($r) => ['val' => $r['val'], 'cnt' => (int)$r['cnt']],
                $db->query($sql)->fetchAll(PDO::FETCH_ASSOC)
            );
        }

        $out['total_leads'] = (int)$db->query("SELECT COUNT(*) FROM import_log_leads")->fetchColumn();

        // Importaciones
        $out['imports'] = array_map(fn($r) => [
            'id'      => (int)$r['id'],
            'archivo' => $r['archivo'],
            'fecha'   => $r['fecha'],
            'leads'   => (int)$r['leads'],
        ], $db->query("
            SELECT il.id, il.archivo, DATE(il.importado_en) AS fecha, COUNT(l.id_lead) AS leads
            FROM import_log il
            LEFT JOIN import_log_leads l ON l.import_id = il.id
            GROUP BY il.id ORDER BY il.id DESC LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC));

        // Geografía: regiones + departamentos (niveles más finos van on-demand vía c98geo_api.php)
        $out['regiones'] = array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => $r['nombre']],
            $db->query("SELECT id, nombre FROM c98_geo.dim_region ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC));
        $out['departamentos'] = array_map(fn($r) => [
            'id' => $r['id'], 'nombre' => $r['nombre'], 'id_region' => (int)$r['id_region'],
        ], $db->query("SELECT id, nombre, id_region FROM c98_geo.dim_departamento ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC));

        // Catálogo de preguntas de encuesta con su taxonomía (dominio › tema › subtema)
        $out['preguntas'] = array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'pregunta' => $r['pregunta'],
            'dominio'  => $r['dominio'],
            'tema'     => $r['tema'],
            'subtema'  => $r['subtema'],
            'codigo'   => $r['codigo'],
            'es_electoral' => (int)$r['es_electoral'],
            'n'        => (int)$r['n'],
        ], $db->query("
            SELECT p.id, p.pregunta, p.es_electoral,
                   dt.dominio, dt.tema, dt.subtema, dt.codigo,
                   (SELECT COUNT(*) FROM import_log_leads_respuestas r WHERE r.pregunta_id = p.id) AS n
            FROM import_log_leads_preguntas p
            LEFT JOIN pregunta_tema pt ON pt.pregunta_id = p.id
            LEFT JOIN dim_tema dt ON dt.id = pt.tema_id
            GROUP BY p.id
            HAVING n > 0
            ORDER BY (dt.codigo IS NULL), dt.codigo, n DESC
        ")->fetchAll(PDO::FETCH_ASSOC));

        // Catálogo de temas/subtemas (dim_tema) con cobertura de leads
        $out['temas'] = array_map(fn($r) => [
            'id'      => (int)$r['id'],
            'dominio' => $r['dominio'],
            'tema'    => $r['tema'],
            'subtema' => $r['subtema'],
            'codigo'  => $r['codigo'],
            'n'       => (int)$r['n'],
        ], $db->query("
            SELECT dt.id, dt.dominio, dt.tema, dt.subtema, dt.codigo,
                   COUNT(DISTINCT r.lead_id) AS n
            FROM dim_tema dt
            LEFT JOIN pregunta_tema pt ON pt.tema_id = dt.id
            LEFT JOIN import_log_leads_respuestas r ON r.pregunta_id = pt.pregunta_id
            GROUP BY dt.id
            ORDER BY dt.codigo
        ")->fetchAll(PDO::FETCH_ASSOC));

        jsonOut($out);
    }

    // ── SEGMENTAR ─────────────────────────────────────────────
    // Aplica el objeto de filtros y devuelve: tamaño del segmento, distribuciones
    // (misma forma que action=analytics → dist) y agregación geográfica para el mapa.
    if ($action === 'segmentar') {
        $db = getDB();
        $filtros = json_decode($_POST['filtros'] ?? file_get_contents('php://input') ?: '{}', true);
        if (!is_array($filtros)) $filtros = [];
        $nivelMapa = $_POST['nivel_mapa'] ?? $filtros['nivel_mapa'] ?? 'departamento';
        if (!in_array($nivelMapa, SEGMENT_GEO_NIVELES, true)) $nivelMapa = 'departamento';

        try {
            $params = [];
            $where  = buildSegmentWhere($filtros, $params);

            $stmtTotal = $db->prepare("SELECT COUNT(*) FROM import_log_leads l $where");
            $stmtTotal->execute($params);
            $total = (int)$stmtTotal->fetchColumn();

            $totalGlobal = (int)$db->query("SELECT COUNT(*) FROM import_log_leads")->fetchColumn();

            // Distribuciones categóricas del segmento
            $distDefs = [
                'sexo'                => ['sexo', 'GROUP BY sexo ORDER BY cnt DESC'],
                'rango_edad'          => ['rango_edad', 'GROUP BY rango_edad ORDER BY FIELD(rango_edad,"18a24","25a34","35a44","45a65+","18-24","25-34","35-44","45-65+") ASC'],
                'estrato'             => ['estrato', 'GROUP BY estrato ORDER BY estrato ASC'],
                'afiliacion_politica' => ['afiliacion_politica', 'GROUP BY afiliacion_politica ORDER BY cnt DESC LIMIT 15'],
                'nivel_educativo'     => ['nivel_educativo', 'GROUP BY nivel_educativo ORDER BY cnt DESC LIMIT 12'],
                'platform'            => ['platform', 'GROUP BY platform ORDER BY cnt DESC'],
            ];
            $dist = [];
            foreach ($distDefs as $key => [$col, $tail]) {
                $sql = "SELECT l.`$col` AS val, COUNT(*) AS cnt FROM import_log_leads l $where AND l.`$col` IS NOT NULL AND l.`$col` <> '' $tail";
                $st = $db->prepare($sql);
                $st->execute($params);
                $dist[$key] = array_map(fn($r) => ['val' => $r['val'], 'cnt' => (int)$r['cnt']],
                    $st->fetchAll(PDO::FETCH_ASSOC));
            }

            // Timeline del segmento (por mes de created_time)
            $stTl = $db->prepare("
                SELECT DATE_FORMAT(l.created_time, '%Y-%m') AS fecha, COUNT(*) AS nuevos
                FROM import_log_leads l $where AND l.created_time IS NOT NULL
                GROUP BY fecha ORDER BY fecha ASC LIMIT 60
            ");
            $stTl->execute($params);
            $timeline = array_map(fn($r) => ['fecha' => $r['fecha'], 'nuevos' => (int)$r['nuevos']],
                $stTl->fetchAll(PDO::FETCH_ASSOC));

            // Conteo de leads del segmento por tema (dim_tema.id) — para que los botones de
            // "Temas" muestren el valor del segmento, no el global.
            $stTema = $db->prepare("
                SELECT pt.tema_id, COUNT(DISTINCT l.id_lead) AS cnt
                FROM import_log_leads l
                JOIN import_log_leads_respuestas r ON r.lead_id = l.id_lead
                JOIN pregunta_tema pt ON pt.pregunta_id = r.pregunta_id
                $where
                GROUP BY pt.tema_id
            ");
            $stTema->execute($params);
            $temasCnt = [];
            foreach ($stTema->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $temasCnt[(string)$r['tema_id']] = (int)$r['cnt'];
            }

            // Agregación geográfica para el mapa (join a c98_geo por nivel)
            $geoMap = [];
            $geoAgg = [
                'region'       => "SELECT dpto.id_region AS geo_id, MAX(reg.nombre) AS nombre, COUNT(*) AS cnt
                                   FROM import_log_leads l
                                   JOIN c98_geo.dim_departamento dpto ON dpto.id = LEFT(l.id_geo,2)
                                   JOIN c98_geo.dim_region reg ON reg.id = dpto.id_region
                                   $where AND l.id_geo IS NOT NULL AND dpto.id_region IS NOT NULL
                                   GROUP BY dpto.id_region",
                'departamento' => "SELECT dpto.id AS geo_id, MAX(dpto.nombre) AS nombre, COUNT(*) AS cnt
                                   FROM import_log_leads l
                                   JOIN c98_geo.dim_departamento dpto ON dpto.id = LEFT(l.id_geo,2)
                                   $where AND l.id_geo IS NOT NULL
                                   GROUP BY dpto.id",
                'subregion'    => "SELECT sr.id AS geo_id, MAX(sr.nombre) AS nombre, COUNT(*) AS cnt
                                   FROM import_log_leads l
                                   JOIN c98_geo.dim_municipio m ON m.id = LEFT(l.id_geo,5)
                                   JOIN c98_geo.dim_subregion sr ON sr.id = m.id_subregion
                                   $where AND l.id_geo IS NOT NULL AND m.id_subregion IS NOT NULL
                                   GROUP BY sr.id",
                'municipio'    => "SELECT m.id AS geo_id, MAX(m.nombre) AS nombre, COUNT(*) AS cnt
                                   FROM import_log_leads l
                                   JOIN c98_geo.dim_municipio m ON m.id = LEFT(l.id_geo,5)
                                   $where AND l.id_geo IS NOT NULL
                                   GROUP BY m.id",
                'zona'         => "SELECT z.id AS geo_id, MAX(z.nombre) AS nombre, COUNT(*) AS cnt
                                   FROM import_log_leads l
                                   JOIN c98_geo.dim_zona z ON z.id = LEFT(l.id_geo,8)
                                   $where AND CHAR_LENGTH(l.id_geo) >= 8
                                   GROUP BY z.id",
                'comuna'       => "SELECT c.id AS geo_id, MAX(c.nombre) AS nombre, COUNT(*) AS cnt
                                   FROM import_log_leads l
                                   JOIN c98_geo.dim_comuna c ON c.id = LEFT(l.id_geo,11)
                                   $where AND CHAR_LENGTH(l.id_geo) >= 11
                                   GROUP BY c.id",
                'barrio'       => "SELECT b.id AS geo_id, MAX(b.nombre) AS nombre, COUNT(*) AS cnt
                                   FROM import_log_leads l
                                   JOIN c98_geo.dim_barrio b ON b.id = l.id_geo
                                   $where AND CHAR_LENGTH(l.id_geo) = 15
                                   GROUP BY b.id",
            ];
            $stGeo = $db->prepare($geoAgg[$nivelMapa]);
            $stGeo->execute($params);
            $geoMap = array_map(fn($r) => [
                'geo_id' => (string)$r['geo_id'], 'nombre' => $r['nombre'], 'cnt' => (int)$r['cnt'],
            ], $stGeo->fetchAll(PDO::FETCH_ASSOC));

            // Leads sin id_geo o con id_geo demasiado corto para el nivel del mapa
            $stSinGeo = $db->prepare("SELECT COUNT(*) FROM import_log_leads l $where AND l.id_geo IS NULL");
            $stSinGeo->execute($params);
            $sinGeo = (int)$stSinGeo->fetchColumn();

            jsonOut([
                'ok'            => true,
                'total'         => $total,
                'total_global'  => $totalGlobal,
                'pct_del_total' => $totalGlobal > 0 ? round($total / $totalGlobal * 100, 2) : 0,
                'dist'          => $dist,
                'timeline'      => $timeline,
                'nivel_mapa'    => $nivelMapa,
                'geo_map'       => $geoMap,
                'sin_geo'       => $sinGeo,
                'mapeados'      => array_sum(array_column($geoMap, 'cnt')),
                'temas_cnt'     => $temasCnt,
            ]);
        } catch (\Throwable $e) {
            errOut('Error en segmentar: ' . $e->getMessage());
        }
    }

    // ── SEGMENTO_EXPORT ───────────────────────────────────────
    // Descarga el segmento como CSV con datos de contacto (para activación).
    if ($action === 'segmento_export') {
        $db = getDB();
        $filtros = json_decode($_POST['filtros'] ?? file_get_contents('php://input') ?: '{}', true);
        if (!is_array($filtros)) $filtros = [];

        $params = [];
        $where  = buildSegmentWhere($filtros, $params);

        $sql = "
            SELECT l.id, l.nombre, l.email, l.telefono, l.sexo, l.rango_edad, l.estrato,
                   l.nivel_educativo, l.afiliacion_politica, l.platform, l.id_geo,
                   dpto.nombre AS departamento, m.nombre AS municipio,
                   l.created_time, l.campaign_name
            FROM import_log_leads l
            LEFT JOIN c98_geo.dim_departamento dpto ON dpto.id = LEFT(l.id_geo,2)
            LEFT JOIN c98_geo.dim_municipio    m    ON m.id    = LEFT(l.id_geo,5)
            $where
            ORDER BY l.id_lead
        ";
        $st = $db->prepare($sql);
        $st->execute($params);

        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="segmento_' . date('Ymd_Hi') . '.csv"');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM para Excel
        fputcsv($fh, ['lead_id', 'nombre', 'email', 'telefono', 'sexo', 'rango_edad', 'estrato',
            'nivel_educativo', 'afiliacion_politica', 'plataforma', 'id_geo', 'departamento',
            'municipio', 'created_time', 'campaign_name']);
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            fputcsv($fh, $row);
        }
        fclose($fh);
        exit;
    }

    // ── MUNICIPIOS_LISTA ──────────────────────────────────────
    // Lista liviana de municipios (c98_geo) para el selector "municipio por defecto" del wizard.
    // `tiene_detalle` = el municipio tiene comunas cargadas (único caso donde comuna/barrio resuelven;
    // hoy son 6: Bogotá, Cali, Medellín, Cartagena, Barranquilla, Rionegro). Todos tienen alguna zona
    // genérica, por eso zona sola no sirve como criterio.
    if ($action === 'municipios_lista') {
        $db = getDB();
        $rows = $db->query("
            SELECT m.id, m.nombre, d.nombre AS departamento,
                   EXISTS(SELECT 1 FROM c98_geo.dim_zona z
                          JOIN c98_geo.dim_comuna c ON c.id_zona = z.id
                          WHERE z.id_municipio = m.id) AS tiene_detalle
            FROM c98_geo.dim_municipio m
            JOIN c98_geo.dim_departamento d ON d.id = m.id_departamento
            ORDER BY tiene_detalle DESC, m.nombre
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['tiene_detalle'] = (int)$r['tiene_detalle']; }
        unset($r);
        jsonOut(['ok' => true, 'municipios' => $rows]);
    }

    errOut('Acción no reconocida: ' . htmlspecialchars($action));

} catch (\Throwable $e) {
    errOut($e->getMessage(), 500);
}
