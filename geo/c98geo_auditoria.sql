-- ============================================================
--  c98_geo :: AUDITORÍA DE INTEGRIDAD REFERENCIAL Y GEOMETRÍA
--  Ejecutar bloque por bloque en phpMyAdmin (pestaña SQL).
--  Ninguna sentencia modifica datos. Solo lectura.
--  Autor: análisis Claude | Fecha: 2026-07-16
-- ============================================================

-- ############################################################
-- BLOQUE 0 — RECONOCIMIENTO DEL ESQUEMA
-- Necesito la salida de estas 3 consultas para el mapeo exacto.
-- ############################################################

-- 0.1 Inventario de tablas y volumen
SELECT TABLE_NAME, TABLE_ROWS, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'c98_geo'
ORDER BY TABLE_NAME;

-- 0.2 Todas las columnas de todas las tablas (el "diccionario")
SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE,
       IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'c98_geo'
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 0.3 Foreign keys declaradas (sospecho que salen 0 filas)
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'c98_geo' AND REFERENCED_TABLE_NAME IS NOT NULL;

-- 0.4 Índices existentes (para saber si los joins están soportados)
SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'c98_geo'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;


-- ############################################################
-- BLOQUE 1 — geo_geometry : PERFIL Y RECONCILIACIÓN
-- ############################################################

-- 1.1 ¿Qué niveles existen y cuántas geometrías tiene cada uno?
--     Esto reconcilia el total de 1.326 filas contra la jerarquía.
SELECT tipo,
       COUNT(*)                                                 AS filas,
       COUNT(DISTINCT id_tipo)                                  AS ids_unicos,
       SUM(geom IS NULL)                                        AS sin_geom,
       SUM(topojson IS NULL)                                    AS sin_topojson,
       SUM(centroid_lat IS NULL OR centroid_lon IS NULL)        AS sin_centroide,
       COUNT(DISTINCT resolution)                               AS resoluciones,
       COUNT(DISTINCT source)                                   AS fuentes,
       MIN(updated_at)                                          AS primera_carga,
       MAX(updated_at)                                          AS ultima_carga
FROM geo_geometry
GROUP BY tipo WITH ROLLUP;

-- 1.2 Duplicados de la llave lógica (tipo, id_tipo, resolution)
--     Debe devolver 0 filas. Si no, hay geometrías pisándose.
SELECT tipo, id_tipo, resolution, COUNT(*) AS repeticiones
FROM geo_geometry
GROUP BY tipo, id_tipo, resolution
HAVING COUNT(*) > 1
ORDER BY repeticiones DESC;

-- 1.3 Trazabilidad de fuente: ¿todo viene del Excel o hay mezcla?
SELECT tipo, source, resolution, COUNT(*) AS filas, MAX(updated_at) AS ultima
FROM geo_geometry
GROUP BY tipo, source, resolution
ORDER BY tipo, filas DESC;

-- 1.4 Cobertura real de polígonos: ¿el 100% es solo centroide?
SELECT
  SUM(geom IS NOT NULL)                                   AS con_poligono,
  SUM(topojson IS NOT NULL)                               AS con_topojson,
  SUM(geom IS NULL AND centroid_lat IS NOT NULL)          AS solo_punto,
  SUM(geom IS NULL AND centroid_lat IS NULL)              AS registro_vacio,
  COUNT(*)                                                AS total
FROM geo_geometry;


-- ############################################################
-- BLOQUE 2 — VALIDACIÓN GEOESPACIAL DE CENTROIDES
-- Bounding box continental + insular de Colombia:
--   lat  -4.30 .. 13.60   |   lon  -82.00 .. -66.80
-- (San Andrés ~12.55/-81.72 queda dentro; Malpelo ~4.00/-81.61 también)
-- ############################################################

-- 2.1 Centroides fuera del territorio colombiano  -> ERROR DURO
SELECT id, tipo, id_tipo, centroid_lat, centroid_lon, source
FROM geo_geometry
WHERE centroid_lat IS NOT NULL
  AND (centroid_lat NOT BETWEEN -4.30 AND 13.60
    OR centroid_lon NOT BETWEEN -82.00 AND -66.80)
ORDER BY tipo, id_tipo;

-- 2.2 Signo invertido: longitud positiva en Colombia es imposible
SELECT id, tipo, id_tipo, centroid_lat, centroid_lon
FROM geo_geometry
WHERE centroid_lon > 0 OR centroid_lat > 15;

-- 2.3 Lat/Lon intercambiadas (patrón clásico de carga desde Excel)
--     Si lat está en el rango típico de lon colombiano, hubo swap.
SELECT id, tipo, id_tipo, centroid_lat, centroid_lon
FROM geo_geometry
WHERE centroid_lat BETWEEN -82 AND -66;

-- 2.4 Coordenada (0,0) o nula disfrazada
SELECT id, tipo, id_tipo, centroid_lat, centroid_lon
FROM geo_geometry
WHERE (centroid_lat = 0 AND centroid_lon = 0)
   OR centroid_lat = centroid_lon;

-- 2.5 Precisión sospechosa: centroides idénticos compartidos por
--     varios registros = hijos heredando el punto del padre.
SELECT centroid_lat, centroid_lon, COUNT(*) AS entidades_en_el_mismo_punto,
       GROUP_CONCAT(CONCAT(tipo,':',id_tipo) ORDER BY tipo SEPARATOR ' | ') AS quienes
FROM geo_geometry
WHERE centroid_lat IS NOT NULL
GROUP BY centroid_lat, centroid_lon
HAVING COUNT(*) > 1
ORDER BY entidades_en_el_mismo_punto DESC
LIMIT 50;

-- 2.6 Redondeo: cuántos decimales trae realmente el centroide.
--     < 4 decimales = precisión insuficiente para nivel barrio (~11 m).
SELECT
  CHAR_LENGTH(SUBSTRING_INDEX(CAST(centroid_lat AS CHAR), '.', -1)) AS decimales_lat,
  COUNT(*) AS filas
FROM geo_geometry
WHERE centroid_lat IS NOT NULL
GROUP BY decimales_lat
ORDER BY decimales_lat;


-- ############################################################
-- BLOQUE 3 — INTEGRIDAD REFERENCIAL POLIMÓRFICA
-- geo_geometry(tipo, id_tipo) es una FK polimórfica: MySQL NO
-- puede enforzarla. Hay que validarla a mano — esto es el núcleo.
-- >>> AJUSTAR los nombres de tabla/columna tras el BLOQUE 0 <<<
-- ############################################################

-- 3.1 Geometrías HUÉRFANAS: apuntan a una entidad que no existe.
--     Repetir por cada valor de `tipo`. Ejemplo para municipio:
SELECT g.id, g.tipo, g.id_tipo, g.source
FROM geo_geometry g
LEFT JOIN geo_municipio m ON m.id_mpio = g.id_tipo
WHERE g.tipo = 'municipio' AND m.id_mpio IS NULL;

--     Ejemplo para departamento:
SELECT g.id, g.tipo, g.id_tipo, g.source
FROM geo_geometry g
LEFT JOIN geo_departamento d ON d.id_dpto = g.id_tipo
WHERE g.tipo = 'departamento' AND d.id_dpto IS NULL;

-- 3.2 Entidades SIN GEOMETRÍA: el inverso, el hueco de cobertura.
SELECT m.id_mpio, m.nombre
FROM geo_municipio m
LEFT JOIN geo_geometry g
       ON g.tipo = 'municipio' AND g.id_tipo = m.id_mpio
WHERE g.id IS NULL;

-- 3.3 Cadena jerárquica rota: municipio -> departamento
SELECT m.id_mpio, m.nombre, m.id_dpto
FROM geo_municipio m
LEFT JOIN geo_departamento d ON d.id_dpto = m.id_dpto
WHERE d.id_dpto IS NULL;

-- 3.4 Coherencia del código DIVIPOLA:
--     los 2 primeros dígitos del código de municipio DEBEN ser
--     el código del departamento. Sin excepción.
SELECT id_mpio, nombre, id_dpto,
       LEFT(LPAD(id_mpio, 5, '0'), 2) AS dpto_derivado_del_codigo
FROM geo_municipio
WHERE LEFT(LPAD(id_mpio, 5, '0'), 2) <> LPAD(id_dpto, 2, '0');

-- 3.5 Duplicados DIVIPOLA a nivel municipio
--     (en el Excel origen hay 5 confirmados: 27250, 50223, 50370, 52418, 52699)
SELECT id_mpio, COUNT(*) AS repeticiones,
       GROUP_CONCAT(nombre SEPARATOR ' || ') AS nombres_en_conflicto
FROM geo_municipio
GROUP BY id_mpio
HAVING COUNT(*) > 1;

-- 3.6 Comuna -> Municipio roto
SELECT c.*
FROM geo_comuna c
LEFT JOIN geo_municipio m ON m.id_mpio = c.id_mpio
WHERE m.id_mpio IS NULL;

-- 3.7 Barrio -> Comuna roto
SELECT b.*
FROM geo_barrio b
LEFT JOIN geo_comuna c ON c.id_comuna = b.id_comuna
WHERE c.id_comuna IS NULL;

-- 3.8 Valores centinela de Excel que se colaron a la BD
--     (#N/A, #REF!, cadenas vacías, 'null' como texto)
SELECT 'geo_municipio.region' AS campo, region AS valor, COUNT(*) AS filas
FROM geo_municipio
WHERE region IN ('#N/A', '#REF!', '#VALUE!', '', 'null', 'NULL', '0')
   OR region IS NULL
GROUP BY region;


-- ############################################################
-- BLOQUE 4 — CONTEOS DE CONTROL (cifras esperadas)
-- ############################################################
-- Regiones .................. 5   (Andina, Caribe, Pacífica, Orinoquía, Amazónica)
-- Departamentos ............ 33   (32 + Bogotá D.C.)
-- Subregiones .............. 97   (según GEO_MPIO del Excel — verificar si es catálogo oficial)
-- Municipios .............. 1123  únicos DIVIPOLA (1128 filas en Excel = 5 duplicados)
-- Comunas/Localidades ...... 68   (solo Bogotá, Medellín, Cali, Barranquilla)
-- Barrios ................. 332   (solo Medellín)
-- ------------------------------------------------------------
-- Suma dptos + mpios ................. 1.156
-- Suma dptos + mpios + comunas ....... 1.224
-- geo_geometry reporta ............... 1.326   <-- diferencia de 102 sin explicar
-- ------------------------------------------------------------
-- El BLOQUE 1.1 debe explicar de dónde salen esas 102 filas.

SELECT 'departamento' AS nivel, COUNT(*) AS filas, COUNT(DISTINCT id_dpto) AS unicos FROM geo_departamento
UNION ALL
SELECT 'municipio', COUNT(*), COUNT(DISTINCT id_mpio) FROM geo_municipio
UNION ALL
SELECT 'comuna', COUNT(*), COUNT(DISTINCT id_comuna) FROM geo_comuna
UNION ALL
SELECT 'barrio', COUNT(*), COUNT(DISTINCT id_barrio) FROM geo_barrio
UNION ALL
SELECT 'geometry', COUNT(*), COUNT(DISTINCT CONCAT(tipo,'-',id_tipo)) FROM geo_geometry;
