<?php
// ============================================================
//  GET · Revisión del repositorio de encuestas (pendientes de importar)
//
//  Recorre la fuente configurada (config.php: ENCUESTAS_FUENTE / ENCUESTAS_RAIZ), detecta las
//  hojas que tienen estructura de encuesta y las contrasta con import_log (archivo + hoja).
//
//  La fuente es un adaptador (FuenteEncuestas). Hoy: carpeta local. Para la nube (Drive / Google
//  Sheets) basta con implementar otra clase y registrarla en fuenteEncuestas(); el escaneo, la
//  tabla de caché y la UI no cambian.
// ============================================================

interface FuenteEncuestas
{
    /** @return array<int,array{ref:string,ruta:string,archivo:string,modificado:int,tamano:int}>
     *  ref = identificador estable para leerHojas() (ruta local, o ID de archivo en la nube). */
    public function listarArchivos(): array;

    /** @return array<int,array{hoja:string,filas:int,cols:int,encabezado:array<int,string>}> */
    public function leerHojas(array $archivo): array;
}

/** Carpeta local (o unidad de Drive montada). Recorre subcarpetas; ignora temporales de Office (~$). */
final class FuenteLocal implements FuenteEncuestas
{
    public function __construct(private string $raiz, private array $extensiones) {}

    public function listarArchivos(): array
    {
        $raiz = rtrim(str_replace('\\', '/', $this->raiz), '/');
        if (!is_dir($raiz)) {
            throw new RuntimeException("La carpeta de encuestas no existe o no está accesible: $raiz");
        }
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $nombre = $f->getFilename();
            if (str_starts_with($nombre, '~$')) continue;
            if (!in_array(strtolower($f->getExtension()), $this->extensiones, true)) continue;
            $ruta = str_replace('\\', '/', $f->getPathname());
            $out[] = [
                'ref'        => $ruta,
                'ruta'       => ltrim(substr($ruta, strlen($raiz)), '/'),
                'archivo'    => $nombre,
                'modificado' => (int)$f->getMTime(),
                'tamano'     => (int)$f->getSize(),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['ruta'], $b['ruta']));
        return $out;
    }

    public function leerHojas(array $archivo): array
    {
        return fuenteLeerHojasDeArchivo($archivo['ref']);
    }
}

/**
 * Google Drive (Excel, CSV y Google Sheets) usando el analizador de Drive (drive_lib.php): cuenta de
 * servicio y carpeta raíz salen de drive_config.php, así que ENCUESTAS_RAIZ no se usa en este modo.
 * Al listar, también sincroniza el inventario `drive_archivos`, que alimenta la pantalla «Archivos de
 * Drive» y la acción «Importar →» (drive_scan.php?action=traer) de esta lista. Un Google Sheet se
 * llama como en import_log: «nombre.xlsx» (así lo registra el importador al traerlo de Drive).
 */
final class FuenteGoogleDrive implements FuenteEncuestas
{
    public function __construct(private array $extensiones) {}

    public function listarArchivos(): array
    {
        require_once __DIR__ . '/drive_lib.php';
        $cfg = driveConfig();
        if (!$cfg) {
            throw new RuntimeException('Drive no está configurado: crea app/drive_config.php y el JSON de la cuenta de servicio (ver drive_config.example.php).');
        }
        $arbol = driveListarArbol($cfg['root_folder']);
        driveSincronizar(getDB(), $arbol);   // inventario completo, sin el filtro de ENCUESTAS_EXT
        $out = [];
        foreach ($arbol as $a) {
            if (!in_array($a['ext'], $this->extensiones, true)) continue;
            $out[] = [
                'ref'        => $a['id'],
                'ruta'       => ($a['ruta'] === '' ? '' : $a['ruta'] . '/') . $a['nombre'],
                'archivo'    => $a['ext'] === 'gsheet' ? $a['nombre'] . '.xlsx' : $a['nombre'],
                'modificado' => (int)strtotime($a['modificado'] . ' UTC'),
                'tamano'     => (int)$a['tamano'],
                'es_sheet'   => $a['ext'] === 'gsheet',
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['ruta'], $b['ruta']));
        return $out;
    }

    public function leerHojas(array $archivo): array
    {
        require_once __DIR__ . '/drive_lib.php';
        if ($archivo['tamano'] > DRIVE_MAX_BYTES) {
            throw new RuntimeException('Pesa más de ' . (DRIVE_MAX_BYTES / 1048576) . ' MB; no se descarga para revisarlo.');
        }
        $esSheet = !empty($archivo['es_sheet']);   // Google Sheet: se exporta a .xlsx para leerlo
        $ext = $esSheet ? 'xlsx' : strtolower(pathinfo($archivo['archivo'], PATHINFO_EXTENSION));
        $dir = __DIR__ . '/tmp/';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $tmp = $dir . 'drvpend_' . bin2hex(random_bytes(6)) . '.' . $ext;   // solo se borra este archivo propio
        try {
            driveDescargar($archivo['ref'], $esSheet, $tmp);
            return fuenteLeerHojasDeArchivo($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}

/** Hojas (nombre, filas, columnas, encabezado) de un archivo local; elige el lector por extensión. */
function fuenteLeerHojasDeArchivo(string $path): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $reader = in_array($ext, ['xlsx', 'xlsm'], true) ? new PhpXlsxReader($path)
            : ($ext === 'csv' ? new PhpCsvReader($path) : new PhpSpreadsheetReader($path));
    try {
        $res = [];
        foreach ($reader->getSheets() as $s) {
            $filas = $reader->readRows($s['name'], 1);
            $res[] = [
                'hoja'       => (string)$s['name'],
                'filas'      => (int)($s['rows'] ?? 0),
                'cols'       => (int)($s['cols'] ?? 0),
                'encabezado' => array_map('strval', $filas[0] ?? []),
            ];
        }
        return $res;
    } finally {
        $reader->close();
    }
}

function fuenteEncuestas(): FuenteEncuestas
{
    $ext = array_values(array_filter(array_map('trim', explode(',', strtolower(ENCUESTAS_EXT)))));
    switch (ENCUESTAS_FUENTE) {
        case 'local':
            return new FuenteLocal(ENCUESTAS_RAIZ, $ext);
        case 'gdrive':
            return new FuenteGoogleDrive($ext);
        default:
            throw new RuntimeException('Fuente de encuestas no soportada: ' . ENCUESTAS_FUENTE);
    }
}

/** Una hoja es de encuesta si su encabezado tiene la estructura del export de Meta Lead Ads:
 *  `id` + `created_time` + alguno de ad_id / adset_id / campaign_id / form_id (tolera "created time"). */
function esEstructuraEncuesta(array $encabezado): bool
{
    $n = [];
    foreach ($encabezado as $c) {
        $c = mb_strtolower(trim((string)$c), 'UTF-8');
        $c = preg_replace('/[^a-z0-9]+/', ' ', $c);
        if ($c !== '') $n[trim($c)] = true;
    }
    if (!isset($n['id']) || !isset($n['created time'])) return false;
    foreach (['ad id', 'adset id', 'campaign id', 'form id'] as $k) if (isset($n[$k])) return true;
    return false;
}

function fuenteAsegurarTabla(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS import_fuente_hojas (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        ref         VARCHAR(500) NOT NULL,
        ruta        VARCHAR(500) NOT NULL,
        archivo     VARCHAR(255) NOT NULL,
        hoja        VARCHAR(255) NOT NULL DEFAULT '',
        filas       INT NOT NULL DEFAULT 0,
        cols        INT NOT NULL DEFAULT 0,
        es_encuesta TINYINT(1) NOT NULL DEFAULT 0,
        modificado  INT NOT NULL DEFAULT 0,
        tamano      BIGINT NOT NULL DEFAULT 0,
        error       VARCHAR(500) DEFAULT NULL,
        fuente      VARCHAR(20) NOT NULL DEFAULT 'local',   -- local | gdrive: de qué fuente salió la fila
        revisado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_ref (ref(190)),
        KEY k_arch_hoja (archivo(150), hoja(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Tabla creada antes de existir la columna `fuente` (todo lo anterior vino de la fuente local)
    $tiene = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_fuente_hojas' AND COLUMN_NAME = 'fuente'")->fetchColumn();
    if (!$tiene) $db->exec("ALTER TABLE import_fuente_hojas ADD COLUMN fuente VARCHAR(20) NOT NULL DEFAULT 'local' AFTER error");
}

/** Revisa la fuente y refresca la caché. Los archivos sin cambios (misma fecha y tamaño) no se
 *  vuelven a abrir, así que las revisiones siguientes son rápidas. */
function fuenteEscanear(PDO $db, ?FuenteEncuestas $fuente = null): array
{
    @set_time_limit(0);
    ignore_user_abort(true);   // si el navegador/Apache corta la espera, la caché se termina de llenar igual
    fuenteAsegurarTabla($db);
    $fuente ??= fuenteEncuestas();
    $archivos = $fuente->listarArchivos();

    // Filas de otra fuente (se cambió ENCUESTAS_FUENTE): sus refs no sirven con la fuente actual
    $db->prepare("DELETE FROM import_fuente_hojas WHERE fuente <> ?")->execute([ENCUESTAS_FUENTE]);

    $previos = [];
    foreach ($db->query("SELECT ref, modificado, tamano, MAX(error IS NOT NULL) AS con_error
                         FROM import_fuente_hojas GROUP BY ref, modificado, tamano") as $r) {
        $previos[$r['ref']] = $r;
    }

    $del = $db->prepare("DELETE FROM import_fuente_hojas WHERE ref = ?");
    $ins = $db->prepare("INSERT INTO import_fuente_hojas
        (ref, ruta, archivo, hoja, filas, cols, es_encuesta, modificado, tamano, error, fuente)
        VALUES (?,?,?,?,?,?,?,?,?,?,'" . ENCUESTAS_FUENTE . "')");
    $vistos = [];
    $res = ['archivos' => count($archivos), 'leidos' => 0, 'sin_cambios' => 0, 'con_error' => 0];

    foreach ($archivos as $a) {
        $vistos[$a['ref']] = true;
        $p = $previos[$a['ref']] ?? null;
        if ($p && (int)$p['modificado'] === $a['modificado'] && (int)$p['tamano'] === $a['tamano'] && !(int)$p['con_error']) {
            $res['sin_cambios']++;
            continue;
        }
        $db->beginTransaction();
        $del->execute([$a['ref']]);
        try {
            foreach ($fuente->leerHojas($a) as $h) {
                $ins->execute([$a['ref'], $a['ruta'], $a['archivo'], $h['hoja'], $h['filas'], $h['cols'],
                               esEstructuraEncuesta($h['encabezado']) ? 1 : 0, $a['modificado'], $a['tamano'], null]);
            }
            $res['leidos']++;
        } catch (Throwable $e) {
            $ins->execute([$a['ref'], $a['ruta'], $a['archivo'], '', 0, 0, 0, $a['modificado'], $a['tamano'],
                           mb_substr($e->getMessage(), 0, 480)]);
            $res['con_error']++;
        }
        $db->commit();
    }

    // Archivos que ya no están en la fuente
    foreach (array_diff_key($previos, $vistos) as $ref => $_) $del->execute([$ref]);

    return $res;
}

/** Hojas con estructura de encuesta contrastadas con import_log, más el resumen y los archivos ilegibles. */
function fuentePendientes(PDO $db): array
{
    fuenteAsegurarTabla($db);
    $q = $db->prepare("SELECT MAX(revisado_en) FROM import_fuente_hojas WHERE fuente = ?");
    $q->execute([ENCUESTAS_FUENTE]);
    $rev = $q->fetchColumn();
    $base = ['ok' => true, 'fuente' => ENCUESTAS_FUENTE, 'importable' => ENCUESTAS_FUENTE === 'gdrive'];
    if (!$rev) return $base + ['revisado_en' => null, 'resumen' => null, 'pendientes' => [], 'errores' => []];

    $fuenteSql = $db->quote(ENCUESTAS_FUENTE);
    $hojas = $db->query("SELECT f.ref, f.ruta, f.archivo, f.hoja, f.filas, f.modificado,
            EXISTS(SELECT 1 FROM import_log il
                   WHERE il.archivo COLLATE utf8mb4_general_ci = f.archivo COLLATE utf8mb4_general_ci
                     AND il.hoja    COLLATE utf8mb4_general_ci = f.hoja    COLLATE utf8mb4_general_ci) AS importada
        FROM import_fuente_hojas f
        WHERE f.es_encuesta = 1 AND f.fuente = $fuenteSql
        ORDER BY f.modificado DESC, f.archivo, f.hoja")->fetchAll();

    // import_log identifica la carga por (archivo, hoja): copias del mismo archivo en otras carpetas
    // cuentan como una sola (se muestra la más reciente y cuántas copias hay).
    $unicas = [];
    foreach ($hojas as $h) {   // ya viene ordenado por modificado DESC
        $k = mb_strtolower($h['archivo'] . "\0" . $h['hoja']);
        if (isset($unicas[$k])) { $unicas[$k]['copias']++; continue; }
        $h['copias'] = 1;
        $unicas[$k] = $h;
    }
    $hojas = array_values($unicas);

    $pend = []; $imp = 0; $vacias = 0;
    foreach ($hojas as $h) {
        if ((int)$h['importada']) { $imp++; continue; }
        if ((int)$h['filas'] <= 1) { $vacias++; continue; }   // solo encabezado: nada que importar
        $pend[] = [
            'ref' => $h['ref'], 'archivo' => $h['archivo'], 'ruta' => $h['ruta'], 'hoja' => $h['hoja'],
            'filas' => (int)$h['filas'] - 1, 'modificado' => (int)$h['modificado'], 'copias' => $h['copias'],
        ];
    }
    $errores = $db->query("SELECT ruta, archivo, error FROM import_fuente_hojas
                           WHERE error IS NOT NULL AND fuente = $fuenteSql ORDER BY ruta")->fetchAll();
    $archivosPend = count(array_unique(array_column($pend, 'ruta')));

    return $base + [
        'revisado_en' => $rev,
        'raiz' => ENCUESTAS_FUENTE === 'gdrive' ? null : ENCUESTAS_RAIZ,
        'resumen' => [
            'hojas_encuesta' => count($hojas), 'importadas' => $imp, 'pendientes' => count($pend),
            'archivos_pendientes' => $archivosPend, 'vacias' => $vacias, 'ilegibles' => count($errores),
        ],
        'pendientes' => $pend,
        'errores' => $errores,
    ];
}
