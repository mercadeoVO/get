# Patrones de query ya probados

## Jerarquía completa de una entidad (JOIN en cadena, no recursivo)

```sql
SELECT r.nombre AS region, dp.nombre AS departamento, sr.nombre AS subregion,
       m.nombre AS municipio, z.nombre AS zona, c.nombre AS comuna, b.nombre AS barrio
FROM dim_barrio b
JOIN dim_comuna c   ON c.id = b.id_comuna
JOIN dim_zona z     ON z.id = c.id_zona
JOIN dim_municipio m ON m.id = z.id_municipio
JOIN dim_departamento dp ON dp.id = m.id_departamento
LEFT JOIN dim_subregion sr ON sr.id = m.id_subregion
JOIN dim_region r   ON r.id = dp.id_region
WHERE b.id = ?;
```

## Geometría de un nivel a una resolución dada (para el mapa)

```sql
SELECT id_tipo, ST_AsGeoJSON(geom) AS geojson, centroid_lat, centroid_lon
FROM geo_geometry
WHERE tipo = 'municipio' AND resolution = 'mid';
```

No pedir `resolution='high'` para todos los municipios a la vez en una sola respuesta — es el
escenario que motivó generar `low`/`mid` (ver coverage.md).

## Verificar el invariante DIVIPOLA (chequeo de salud rápido)

```sql
SELECT id, id_departamento
FROM dim_municipio
WHERE LEFT(id, 2) <> id_departamento;
-- Debe devolver 0 filas. Si no, hay corrupción de datos, no un bug de query.
```

## Detectar huérfanos en `geo_geometry` (no hay FK de motor, hay que chequear a mano)

```sql
-- Ejemplo para tipo='municipio'; repetir cambiando tipo y tabla de entidad
SELECT g.id, g.id_tipo
FROM geo_geometry g
LEFT JOIN dim_municipio m ON m.id = g.id_tipo
WHERE g.tipo = 'municipio' AND m.id IS NULL;
```

## Cross-DB: leads por departamento (patrón MEDFUT)

```sql
SELECT dpto.nombre, COUNT(*) AS leads
FROM C98_MEDFUT.import_log_leads l
LEFT JOIN c98_geo.dim_departamento dpto ON dpto.id = LEFT(l.id_geo, 2)
WHERE l.id_geo IS NOT NULL
GROUP BY dpto.nombre
ORDER BY leads DESC;
```
