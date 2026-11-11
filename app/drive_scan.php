<?php
// ============================================================
//  GET · API del analizador de Drive (ver drive_lib.php)
//    ?action=estado     — ¿Drive configurado?, resumen del inventario
//    ?action=escanear   — recorre la carpeta raíz y sincroniza el inventario (POST)
//    ?action=analizar   — file_id: descarga y revisa las columnas de UN archivo (POST)
//    ?action=listar     — inventario con estado de columnas y si ya se importó
//    ?action=traer      — file_id: descarga el archivo a tmp/ y devuelve temp_id + hojas
//                         para continuar en el asistente de Importar (POST)
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/drive_lib.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

function out($d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$post   = $_SERVER['REQUEST_METHOD'] === 'POST';
try {
    $db = getDB();
    driveEnsureTabla($db);

    if ($action === 'estado') {
        $res = $db->query("SELECT estado, COUNT(*) n FROM drive_archivos GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
        out(['ok' => true, 'configurado' => driveConfig() !== null, 'columnas' => driveColumnasRequeridas(),
             'resumen' => $res, 'ultimo_escaneo' => $db->query("SELECT MAX(visto_en) FROM drive_archivos")->fetchColumn() ?: null]);
    }

    if ($action === 'escanear') {
        if (!$post) out(['ok' => false, 'error' => 'Usa POST.'], 405);
        $cfg = driveConfig();
        if (!$cfg) out(['ok' => false, 'error' => 'Drive no está configurado: crea app/drive_config.php (ver drive_config.example.php) y el JSON de la cuenta de servicio.'], 412);
        $arch = driveListarArbol($cfg['root_folder']);
        out(['ok' => true] + driveSincronizar($db, $arch));
    }

    if ($action === 'analizar') {
        if (!$post) out(['ok' => false, 'error' => 'Usa POST.'], 405);
        $id = trim((string)($_POST['file_id'] ?? ''));
        if ($id === '') out(['ok' => false, 'error' => 'Falta file_id.'], 400);
        $f = driveAnalizarUno($db, $id);
        out(['ok' => true, 'archivo' => ['file_id' => $f['file_id'], 'estado' => $f['estado'], 'error' => $f['error']]]);
    }

    if ($action === 'listar') {
        $rows = $db->query("SELECT d.file_id, d.nombre, d.ruta, d.ext, d.tamano, d.modificado, d.enlace, d.estado, d.hojas, d.error,
                                   d.analizado_en,
                                   (SELECT COUNT(*) FROM import_log il WHERE il.archivo = d.nombre
                                       OR (d.ext = 'gsheet' AND il.archivo = CONCAT(d.nombre, '.xlsx'))) AS importaciones,
                                   (SELECT MAX(il.importado_en) FROM import_log il WHERE il.archivo = d.nombre
                                       OR (d.ext = 'gsheet' AND il.archivo = CONCAT(d.nombre, '.xlsx'))) AS ultima_importacion
                            FROM drive_archivos d ORDER BY d.modificado DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['hojas'] = $r['hojas'] ? json_decode($r['hojas'], true) : []; $r['tamano'] = (int)$r['tamano']; $r['importaciones'] = (int)$r['importaciones']; }
        out(['ok' => true, 'columnas' => driveColumnasRequeridas(), 'archivos' => $rows]);
    }

    if ($action === 'traer') {
        if (!$post) out(['ok' => false, 'error' => 'Usa POST.'], 405);
        $id = trim((string)($_POST['file_id'] ?? ''));
        $st = $db->prepare("SELECT nombre, ext, tamano FROM drive_archivos WHERE file_id = ?"); $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) out(['ok' => false, 'error' => 'El archivo no está en el inventario.'], 404);
        $esSheet = $f['ext'] === 'gsheet';
        $ext = $esSheet ? 'xlsx' : $f['ext'];
        $tmpName = 'lead_' . bin2hex(random_bytes(8)) . '.' . $ext;      // mismo formato que handleUpload()
        $dir = __DIR__ . '/tmp/'; if (!is_dir($dir)) mkdir($dir, 0750, true);
        driveDescargar($id, $esSheet, $dir . $tmpName);
        $reader = in_array($ext, ['xlsx', 'xlsm'], true) ? new PhpXlsxReader($dir . $tmpName)
                : ($ext === 'csv' ? new PhpCsvReader($dir . $tmpName) : new PhpSpreadsheetReader($dir . $tmpName));
        $sheets = $reader->getSheets(); $reader->close();
        $archivo = $esSheet ? $f['nombre'] . '.xlsx' : $f['nombre'];
        $st = $db->prepare("SELECT hoja, MAX(importado_en) AS ultima_carga, SUM(filas_ins) AS leads_ins,
                                   MAX(url_archivo) AS url_archivo, MAX(url_carpeta) AS url_carpeta
                            FROM import_log WHERE archivo = ? GROUP BY hoja");
        $st->execute([$archivo]);
        out(['ok' => true, 'temp_id' => $tmpName, 'archivo' => $archivo, 'size' => (int)$f['tamano'],
             'sheets' => $sheets, 'imported_sheets' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    out(['ok' => false, 'error' => 'Acción no reconocida.'], 404);
} catch (\Throwable $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
