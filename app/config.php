<?php
// ============================================================
//  GET · Configuración de conexión MySQL / XAMPP
// ============================================================
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3307);
define('DB_NAME', 'C98_GET');
define('DB_USER', 'root');
define('DB_PASS', '');  // Cambia si tienes contraseña en root

// ── Repositorio de encuestas (revisión de pendientes de importar) ──
// 'local': carpeta local (Google Drive para escritorio montado en G:), sin credenciales.
// 'gdrive': API de Drive con cuenta de servicio (drive_config.php, ver drive_config.example.php);
//           lee Excel, CSV y Google Sheets, comparte inventario con la pantalla «Archivos de Drive»
//           y habilita «Importar →» en la lista de pendientes. Para migrar basta con
//           define('ENCUESTAS_FUENTE', 'gdrive'). Para un equipo distinto sin editar este archivo,
//           crear config_local.php (no versionado) con los mismos define().
if (is_file(__DIR__ . '/config_local.php')) require_once __DIR__ . '/config_local.php';
if (!defined('ENCUESTAS_FUENTE')) define('ENCUESTAS_FUENTE', 'local');  // 'local' (carpeta/unidad montada) | 'gdrive' (API de Drive)
if (!defined('ENCUESTAS_RAIZ'))   define('ENCUESTAS_RAIZ',   'G:/Mi unidad/CAG-VO/Dash_OP'); // solo 'local'; con 'gdrive' la carpeta sale de drive_config.php
if (!defined('ENCUESTAS_EXT'))    define('ENCUESTAS_EXT',    ENCUESTAS_FUENTE === 'gdrive' ? 'xlsx,xlsm,gsheet' : 'xlsx,xlsm');  // 'gsheet' = Google Sheets

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
