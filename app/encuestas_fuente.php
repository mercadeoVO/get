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
        $reader = new PhpXlsxReader($archivo['ref']);
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
}

function fuenteEncuestas(): FuenteEncuestas
{
    $ext = array_values(array_filter(array_map('trim', explode(',', strtolower(ENCUESTAS_EXT)))));
    switch (ENCUESTAS_FUENTE) {
        case 'local':
            return new FuenteLocal(ENCUESTAS_RAIZ, $ext);
        // case 'gdrive': return new FuenteGoogleDrive(ENCUESTAS_RAIZ /* ID de carpeta */, ...);  // futuro
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
        revisado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_ref (ref(190)),
        KEY k_arch_hoja (archivo(150), hoja(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Revisa la fuente y refresca la caché. Los archivos sin cambios (misma fecha y tamaño) no se
 *  vuelven a abrir, así que las revisiones siguientes son rápidas. */
function fuenteEscanear(PDO $db): array
{
    @set_time_limit(0);
    ignore_user_abort(true);   // si el navegador/Apache corta la espera, la caché se termina de llenar igual
    fuenteAsegurarTabla($db);
    $fuente = fuenteEncuestas();
    $archivos = $fuente->listarArchivos();

    $previos = [];
    foreach ($db->query("SELECT ref, modificado, tamano, MAX(error IS NOT NULL) AS con_error
                         FROM import_fuente_hojas GROUP BY ref, modificado, tamano") as $r) {
        $previos[$r['ref']] = $r;
    }

    $del = $db->prepare("DELETE FROM import_fuente_hojas WHERE ref = ?");
    $ins = $db->prepare("INSERT INTO import_fuente_hojas
        (ref, ruta, archivo, hoja, filas, cols, es_encuesta, modificado, tamano, error)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
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
    $rev = $db->query("SELECT MAX(revisado_en) FROM import_fuente_hojas")->fetchColumn();
    if (!$rev) return ['ok' => true, 'revisado_en' => null, 'resumen' => null, 'pendientes' => [], 'errores' => []];

    $hojas = $db->query("SELECT f.ruta, f.archivo, f.hoja, f.filas, f.modificado,
            EXISTS(SELECT 1 FROM import_log il
                   WHERE il.archivo COLLATE utf8mb4_general_ci = f.archivo COLLATE utf8mb4_general_ci
                     AND il.hoja    COLLATE utf8mb4_general_ci = f.hoja    COLLATE utf8mb4_general_ci) AS importada
        FROM import_fuente_hojas f
        WHERE f.es_encuesta = 1
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
            'archivo' => $h['archivo'], 'ruta' => $h['ruta'], 'hoja' => $h['hoja'],
            'filas' => (int)$h['filas'] - 1, 'modificado' => (int)$h['modificado'], 'copias' => $h['copias'],
        ];
    }
    $errores = $db->query("SELECT ruta, archivo, error FROM import_fuente_hojas WHERE error IS NOT NULL ORDER BY ruta")->fetchAll();
    $archivosPend = count(array_unique(array_column($pend, 'ruta')));

    return [
        'ok' => true,
        'revisado_en' => $rev,
        'fuente' => ENCUESTAS_FUENTE,
        'raiz' => ENCUESTAS_RAIZ,
        'resumen' => [
            'hojas_encuesta' => count($hojas), 'importadas' => $imp, 'pendientes' => count($pend),
            'archivos_pendientes' => $archivosPend, 'vacias' => $vacias, 'ilegibles' => count($errores),
        ],
        'pendientes' => $pend,
        'errores' => $errores,
    ];
}
