<?php
/**
 * MEDFUT · Setup dim_geo
 * Ejecutar UNA VEZ: http://localhost/medfut/dim_geo_setup.php
 * Crea la tabla dim_geo y carga el seed DIVIPOLA Colombia (1,526 registros)
 */
require_once __DIR__ . '/config.php';

set_time_limit(120);
header('Content-Type: text/html; charset=utf-8');

function run(): array {
    $log = [];
    $db  = getDB();

    // 1. Crear tabla
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS dim_geo (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            nivel           TINYINT NOT NULL COMMENT '4=municipio 6=comuna 7=barrio',
            cod_region      VARCHAR(10)  NULL,
            nom_region      VARCHAR(100) NULL,
            abr_region      VARCHAR(20)  NULL,
            cod_dpto        VARCHAR(10)  NULL,
            nom_dpto        VARCHAR(100) NULL,
            abr_dpto        VARCHAR(20)  NULL,
            cod_subregion   VARCHAR(30)  NULL,
            nom_subregion   VARCHAR(100) NULL,
            abr_subregion   VARCHAR(20)  NULL,
            cod_mpio        VARCHAR(10)  NULL,
            nom_mpio        VARCHAR(150) NULL,
            abr_mpio        VARCHAR(50)  NULL,
            cod_zona        VARCHAR(20)  NULL,
            nom_zona        VARCHAR(100) NULL,
            abr_zona        VARCHAR(20)  NULL,
            cod_comuna      VARCHAR(20)  NULL,
            nom_comuna      VARCHAR(200) NULL,
            abr_comuna      VARCHAR(50)  NULL,
            cod_barrio      VARCHAR(20)  NULL,
            nom_barrio      VARCHAR(200) NULL,
            abr_barrio      VARCHAR(50)  NULL,
            lat             DECIMAL(10,7) NULL,
            lon             DECIMAL(10,7) NULL,
            iso_3166        VARCHAR(20)  NULL,
            activo          TINYINT(1)   DEFAULT 1,
            creado_en       DATETIME     DEFAULT CURRENT_TIMESTAMP,
            KEY idx_nivel      (nivel),
            KEY idx_cod_mpio   (cod_mpio),
            KEY idx_nom_mpio   (nom_mpio(40)),
            KEY idx_abr_mpio   (abr_mpio(30)),
            KEY idx_nom_barrio (nom_barrio(40)),
            KEY idx_nom_comuna (nom_comuna(40)),
            FULLTEXT KEY ft_geo (nom_mpio, nom_dpto, nom_barrio, nom_comuna, nom_subregion)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Geografía Colombia DIVIPOLA: Región·Dpto·Subregión·Municipio·Zona·Comuna·Barrio'");
        $log[] = '✅ Tabla dim_geo creada (o ya existía).';
    } catch (\Throwable $e) {
        $log[] = '❌ Error creando tabla: ' . $e->getMessage();
        return $log;
    }

    // 2. Verificar si ya tiene datos (1526 = completo)
    $existing = (int)$db->query("SELECT COUNT(*) FROM dim_geo")->fetchColumn();
    if ($existing >= 1526) {
        $log[] = "ℹ️ dim_geo ya tiene $existing registros — seed omitido.";
        return $log;
    }
    if ($existing > 0) {
        // Datos parciales → limpiar y recargar
        $db->exec("TRUNCATE TABLE dim_geo");
        $log[] = "⚠️ dim_geo tenía $existing registros parciales → limpiada para recargar.";
    }

    // 3. Cargar seed
    $seedFile = __DIR__ . '/dim_geo_seed.sql';
    if (!file_exists($seedFile)) {
        $log[] = '❌ dim_geo_seed.sql no encontrado en ' . __DIR__;
        return $log;
    }

    $seedSql = file_get_contents($seedFile);
    $stmts   = preg_split('/;\s*\n/', $seedSql);
    $ok = 0; $fail = 0;

    foreach ($stmts as $stmt) {
        $stmt = trim($stmt);
        if (!$stmt || stripos($stmt, 'INSERT') === false) continue;
        try {
            $db->exec($stmt);
            $ok++;
        } catch (\Throwable $e) {
            $fail++;
        }
    }

    $total = (int)$db->query("SELECT COUNT(*) FROM dim_geo")->fetchColumn();
    $log[] = "✅ Seed cargado: $ok bloques INSERT ejecutados ($fail fallidos).";
    $log[] = "📊 Total registros en dim_geo: <strong>$total</strong>";

    // Distribución por nivel
    $dist = $db->query("SELECT nivel, COUNT(*) AS cnt FROM dim_geo GROUP BY nivel ORDER BY nivel")
               ->fetchAll(\PDO::FETCH_ASSOC);
    $labels = [4=>'Municipios', 6=>'Comunas', 7=>'Barrios'];
    foreach ($dist as $d) {
        $lbl = $labels[$d['nivel']] ?? "Nivel {$d['nivel']}";
        $log[] = "&nbsp;&nbsp;&nbsp;• $lbl: {$d['cnt']}";
    }

    return $log;
}

$lines = run();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>dim_geo Setup — MEDFUT</title>
<style>
  body { font-family: monospace; background: #111; color: #e0e0e0; padding: 40px; }
  h2   { color: #f59e0b; }
  p    { margin: 6px 0; font-size: 14px; line-height: 1.6; }
  .box { background:#1a1a1a; border:1px solid #333; border-radius:8px; padding:24px; max-width:640px; }
  a    { color: #3b82f6; }
</style>
</head>
<body>
<div class="box">
  <h2>🗺️ dim_geo Setup — DIVIPOLA Colombia</h2>
  <?php foreach ($lines as $l) echo "<p>$l</p>"; ?>
  <p style="margin-top:24px;color:#666;">
    <a href="leads_import.html">← Volver al importador</a>
  </p>
</div>
</body>
</html>
