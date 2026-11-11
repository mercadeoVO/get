<?php
// ============================================================
//  GET · Analizador de archivos de Google Drive
//
//  Recorre una carpeta de Drive (recursivo), encuentra los .xlsx / .xls / .ods / .csv /
//  Google Sheets y revisa por dentro si traen las columnas de un export de leads de Meta Ads:
//  id, created_time, ad_id, ad_name, adset_id, adset_name, campaign_id, campaign_name,
//  form_id, form_name, is_organic, platform.
//
//  Sin Composer: la cuenta de servicio se autentica firmando el JWT con OpenSSL + cURL.
//  Credenciales y carpeta raíz: ver drive_config.example.php (drive_config.php no se versiona).
//  Las funciones puras (normalizarEncabezado, evaluarEncabezados, analizarArchivoLocal) no
//  tocan la red, así que se pueden probar con archivos locales.
// ============================================================

require_once __DIR__ . '/ExcelReaderPhp.php';

const DRIVE_MIME_FOLDER = 'application/vnd.google-apps.folder';
const DRIVE_MIME_SHEET  = 'application/vnd.google-apps.spreadsheet';
const DRIVE_MIME_XLSX   = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
/** Tope de descarga para analizar (bytes); por encima el archivo queda "omitido". */
const DRIVE_MAX_BYTES   = 250 * 1024 * 1024;

/** Columnas que debe traer un export de leads, en el orden pedido. */
function driveColumnasRequeridas(): array {
    return ['id', 'created_time', 'ad_id', 'ad_name', 'adset_id', 'adset_name',
            'campaign_id', 'campaign_name', 'form_id', 'form_name', 'is_organic', 'platform'];
}

// ── Configuración y autenticación ────────────────────────────

/** @return array{sa_file:string,root_folder:string}|null  null si falta drive_config.php o el JSON de la cuenta. */
function driveConfig(): ?array {
    $f = __DIR__ . '/drive_config.php';
    if (!is_file($f)) return null;
    $cfg = require $f;
    if (!is_array($cfg) || empty($cfg['sa_file']) || empty($cfg['root_folder'])) return null;
    $sa = $cfg['sa_file'];
    if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $sa)) $sa = __DIR__ . '/' . $sa;   // ruta relativa a app/
    if (!is_file($sa)) return null;
    return ['sa_file' => $sa, 'root_folder' => (string)$cfg['root_folder']];
}

function driveB64Url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Token OAuth2 (scope solo lectura) de la cuenta de servicio; se reutiliza mientras no venza. */
function driveToken(): string {
    static $tok = null, $exp = 0;
    if ($tok && time() < $exp - 60) return $tok;
    $cfg = driveConfig();
    if (!$cfg) throw new RuntimeException('Drive no está configurado (falta drive_config.php o el JSON de la cuenta de servicio).');
    $sa = json_decode((string)file_get_contents($cfg['sa_file']), true);
    if (empty($sa['client_email']) || empty($sa['private_key'])) throw new RuntimeException('El JSON de la cuenta de servicio no tiene client_email / private_key.');
    $now = time();
    $head = driveB64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $body = driveB64Url(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/drive.readonly',
        'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
    ]));
    $sig = '';
    if (!openssl_sign("$head.$body", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('No se pudo firmar el JWT (private_key inválida).');
    $ch = curl_init($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$body." . driveB64Url($sig)])]);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($r['access_token'])) throw new RuntimeException('Google rechazó la autenticación: ' . ($r['error_description'] ?? $r['error'] ?? 'sin respuesta'));
    $tok = $r['access_token']; $exp = $now + (int)($r['expires_in'] ?? 3600);
    return $tok;
}

/** GET JSON a la API de Drive v3. */
function driveGet(string $path, array $query = []): array {
    $ch = curl_init('https://www.googleapis.com/drive/v3/' . $path . '?' . http_build_query($query));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . driveToken()]]);
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $r = json_decode($raw, true);
    if ($code >= 400 || !is_array($r)) throw new RuntimeException('Drive API ' . $code . ': ' . ($r['error']['message'] ?? substr($raw, 0, 200)));
    return $r;
}

// ── Listado recursivo ────────────────────────────────────────

/**
 * Lista todos los archivos bajo $rootId (subcarpetas incluidas).
 * @return array<int,array{id:string,nombre:string,ruta:string,mime:string,ext:string,tamano:int,modificado:string,enlace:string}>
 */
function driveListarArbol(string $rootId): array {
    $out = []; $cola = [[$rootId, '']];
    while ($cola) {
        [$folder, $ruta] = array_shift($cola);
        $page = null;
        do {
            $q = ['q' => "'$folder' in parents and trashed = false", 'pageSize' => 1000,
                  'fields' => 'nextPageToken, files(id,name,mimeType,size,modifiedTime,webViewLink)',
                  'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true'];
            if ($page) $q['pageToken'] = $page;
            $r = driveGet('files', $q);
            foreach ($r['files'] ?? [] as $f) {
                if ($f['mimeType'] === DRIVE_MIME_FOLDER) { $cola[] = [$f['id'], ($ruta === '' ? '' : $ruta . '/') . $f['name']]; continue; }
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if ($f['mimeType'] === DRIVE_MIME_SHEET) $ext = 'gsheet';
                if (!in_array($ext, driveExtensionesAnalizables(), true)) continue;
                $out[] = ['id' => $f['id'], 'nombre' => $f['name'], 'ruta' => $ruta, 'mime' => $f['mimeType'], 'ext' => $ext,
                          'tamano' => (int)($f['size'] ?? 0), 'modificado' => substr(str_replace('T', ' ', $f['modifiedTime'] ?? ''), 0, 19),
                          'enlace' => $f['webViewLink'] ?? ''];
            }
            $page = $r['nextPageToken'] ?? null;
        } while ($page);
    }
    return $out;
}

function driveExtensionesAnalizables(): array { return ['xlsx', 'xlsm', 'xls', 'ods', 'csv', 'gsheet']; }

// ── Descarga ─────────────────────────────────────────────────

/** Descarga un archivo (o exporta un Google Sheet a .xlsx) a $dest. */
function driveDescargar(string $fileId, bool $esSheet, string $dest): void {
    $url = $esSheet
        ? "https://www.googleapis.com/drive/v3/files/$fileId/export?" . http_build_query(['mimeType' => DRIVE_MIME_XLSX])
        : "https://www.googleapis.com/drive/v3/files/$fileId?alt=media&supportsAllDrives=true";
    $fh = fopen($dest, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 600, CURLOPT_FAILONERROR => false,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . driveToken()]]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch); fclose($fh);
    if ($code >= 400 || $err) {
        $msg = is_file($dest) ? substr((string)file_get_contents($dest, false, null, 0, 300), 0, 200) : $err;
        @unlink($dest);
        throw new RuntimeException("No se pudo descargar ($code): $msg");
    }
}

// ── Revisión de columnas (sin red) ───────────────────────────

/** "Created Time", "created_time" y "CREATED-TIME" → "createdtime". */
function normalizarEncabezado($h): string {
    $s = mb_strtolower(trim((string)$h), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

/**
 * Compara los encabezados de una hoja con las 12 columnas requeridas.
 * @return array{presentes:string[],faltantes:string[]}
 */
function evaluarEncabezados(array $encabezados): array {
    $tiene = array_flip(array_filter(array_map('normalizarEncabezado', $encabezados), fn($x) => $x !== ''));
    $presentes = $faltantes = [];
    foreach (driveColumnasRequeridas() as $c) {
        if (isset($tiene[normalizarEncabezado($c)])) $presentes[] = $c; else $faltantes[] = $c;
    }
    return ['presentes' => $presentes, 'faltantes' => $faltantes];
}

/**
 * Abre un archivo local y evalúa cada hoja.
 * @return array{estado:string,hojas:array<int,array{hoja:string,filas:int,presentes:string[],faltantes:string[],completa:bool}>}
 *   estado: 'completo' (alguna hoja con las 12) | 'parcial' (alguna con al menos una) | 'sin_columnas'
 */
function analizarArchivoLocal(string $path): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $reader = in_array($ext, ['xlsx', 'xlsm'], true) ? new PhpXlsxReader($path)
            : ($ext === 'csv' ? new PhpCsvReader($path) : new PhpSpreadsheetReader($path));
    $hojas = []; $mejor = 'sin_columnas';
    try {
        foreach ($reader->getSheets() as $sh) {
            $filas = $reader->readRows($sh['name'], 1);
            $ev = evaluarEncabezados($filas[0] ?? []);
            $completa = !$ev['faltantes'] && $ev['presentes'];
            $hojas[] = ['hoja' => $sh['name'], 'filas' => (int)($sh['rows'] ?? 0), 'presentes' => $ev['presentes'],
                        'faltantes' => $ev['faltantes'], 'completa' => (bool)$completa];
            if ($completa) $mejor = 'completo';
            elseif ($ev['presentes'] && $mejor !== 'completo') $mejor = 'parcial';
        }
    } finally { $reader->close(); }
    return ['estado' => $mejor, 'hojas' => $hojas];
}

// ── Tabla de inventario ──────────────────────────────────────

function driveEnsureTabla(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS drive_archivos (
        file_id        VARCHAR(80)  NOT NULL PRIMARY KEY,
        nombre         VARCHAR(300) NOT NULL,
        ruta           VARCHAR(600) NOT NULL DEFAULT '',
        ext            VARCHAR(10)  NOT NULL,
        tamano         BIGINT       NOT NULL DEFAULT 0,
        modificado     DATETIME     NULL,
        enlace         VARCHAR(400) NULL,
        estado         VARCHAR(20)  NOT NULL DEFAULT 'pendiente',   -- pendiente|completo|parcial|sin_columnas|omitido|error
        hojas          LONGTEXT     NULL,                           -- JSON de analizarArchivoLocal()['hojas']
        error          VARCHAR(400) NULL,
        analizado_mod  DATETIME     NULL,                           -- 'modificado' que tenía al analizarse
        analizado_en   DATETIME     NULL,
        visto_en       DATETIME     NOT NULL,
        INDEX idx_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Sincroniza el listado de Drive con la tabla: los archivos nuevos o modificados vuelven a 'pendiente'
 * (hay que re-analizarlos); los que ya no están en Drive se borran del inventario.
 * @return array{total:int,nuevos:int,modificados:int,eliminados:int}
 */
function driveSincronizar(PDO $db, array $archivos): array {
    driveEnsureTabla($db);
    $actual = $db->query("SELECT file_id, modificado, analizado_mod, estado FROM drive_archivos")->fetchAll(PDO::FETCH_UNIQUE);
    $nuevos = $mod = 0; $ahora = date('Y-m-d H:i:s'); $vistos = [];
    $ins = $db->prepare("INSERT INTO drive_archivos (file_id,nombre,ruta,ext,tamano,modificado,enlace,estado,visto_en)
                         VALUES (?,?,?,?,?,?,?, 'pendiente', ?)
                         ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), ruta=VALUES(ruta), ext=VALUES(ext), tamano=VALUES(tamano),
                              modificado=VALUES(modificado), enlace=VALUES(enlace), visto_en=VALUES(visto_en),
                              estado = IF(analizado_mod IS NULL OR analizado_mod <> VALUES(modificado), 'pendiente', estado)");
    foreach ($archivos as $a) {
        $vistos[] = $a['id'];
        if (!isset($actual[$a['id']])) $nuevos++;
        elseif ($actual[$a['id']]['analizado_mod'] !== null && $actual[$a['id']]['analizado_mod'] !== $a['modificado']) $mod++;
        $ins->execute([$a['id'], $a['nombre'], $a['ruta'], $a['ext'], $a['tamano'], $a['modificado'] ?: null, $a['enlace'], $ahora]);
    }
    $eliminados = 0;
    foreach (array_diff(array_keys($actual), $vistos) as $id) { $db->prepare("DELETE FROM drive_archivos WHERE file_id = ?")->execute([$id]); $eliminados++; }
    return ['total' => count($archivos), 'nuevos' => $nuevos, 'modificados' => $mod, 'eliminados' => $eliminados];
}

/** Descarga, analiza y guarda el resultado de UN archivo del inventario. Devuelve la fila actualizada. */
function driveAnalizarUno(PDO $db, string $fileId): array {
    driveEnsureTabla($db);
    $st = $db->prepare("SELECT * FROM drive_archivos WHERE file_id = ?"); $st->execute([$fileId]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) throw new RuntimeException('El archivo no está en el inventario; escanea la carpeta primero.');

    $upd = $db->prepare("UPDATE drive_archivos SET estado=?, hojas=?, error=?, analizado_mod=?, analizado_en=NOW() WHERE file_id=?");
    if ((int)$f['tamano'] > DRIVE_MAX_BYTES) {
        $upd->execute(['omitido', null, 'Pesa más de ' . (DRIVE_MAX_BYTES / 1048576) . ' MB', $f['modificado'], $fileId]);
    } else {
        $dir = __DIR__ . '/tmp/'; if (!is_dir($dir)) mkdir($dir, 0750, true);
        $esSheet = $f['ext'] === 'gsheet';
        $tmp = $dir . 'drvscan_' . bin2hex(random_bytes(6)) . '.' . ($esSheet ? 'xlsx' : $f['ext']);
        try {
            driveDescargar($fileId, $esSheet, $tmp);
            $r = analizarArchivoLocal($tmp);
            $upd->execute([$r['estado'], json_encode($r['hojas'], JSON_UNESCAPED_UNICODE), null, $f['modificado'], $fileId]);
        } catch (\Throwable $e) {
            $upd->execute(['error', null, mb_substr($e->getMessage(), 0, 390), $f['modificado'], $fileId]);
        } finally { @unlink($tmp); }
    }
    $st->execute([$fileId]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
