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
// Hoy es una carpeta local (Google Drive para escritorio montado en G:). Al migrar a la nube,
// cambiar ENCUESTAS_FUENTE e implementar el adaptador correspondiente en encuestas_fuente.php;
// el resto de la aplicación no se toca. Para un equipo distinto sin editar este archivo, crear
// config_local.php (no versionado) con los mismos define().
if (is_file(__DIR__ . '/config_local.php')) require_once __DIR__ . '/config_local.php';
if (!defined('ENCUESTAS_FUENTE')) define('ENCUESTAS_FUENTE', 'local');  // 'local' | (futuro) 'gdrive'
if (!defined('ENCUESTAS_RAIZ'))   define('ENCUESTAS_RAIZ',   'G:/Mi unidad/CAG-VO/Dash_OP'); // carpeta o, en la nube, ID de carpeta
if (!defined('ENCUESTAS_EXT'))    define('ENCUESTAS_EXT',    'xlsx,xlsm');                   // futuro: 'gsheet' = Google Sheets

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
