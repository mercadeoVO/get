---
name: c98geo-data-analyst
description: Conocimiento específico del esquema de datos de C98_GEO (BD `c98_geo`) y su cruce con MEDFUT (BD `C98_MEDFUT`, antes `medfut`), ambas en MariaDB 127.0.0.1:3307. Úsala para cualquier query, migración, análisis geoespacial o troubleshooting sobre la jerarquía geográfica de Colombia (región→departamento→subregión→municipio→zona→comuna→barrio) o sobre leads cruzados con geografía.
---

# C98_GEO / MEDFUT — Contexto de datos del proyecto

Esta skill resume lo ya descubierto y verificado en sesiones anteriores contra la BD real, para
que no haga falta re-explorar el esquema desde cero cada vez. Fuente original de verdad:
`C:\Users\impag\Documents\C98_MEDFUT\geo\docs\diccionario_datos.md` (más detallado, con hallazgos
H-01 a H-09) y `C:\Users\impag\Documents\C98_MEDFUT\geo\docs\procesos.md` (estado por proceso).

**Si esta skill y `diccionario_datos.md` se contradicen, gana `diccionario_datos.md`** (o mejor:
una query en vivo contra la BD) — esta skill puede quedar desactualizada si el esquema cambia.

## Navegación de la base de conocimiento

- [references/schema.md](references/schema.md) — las 7 tablas dimensionales + `geo_geometry`, columnas clave, FKs reales.
- [references/divipola_codes.md](references/divipola_codes.md) — convención de códigos DIVIPOLA y el invariante que nunca debe violarse.
- [references/gotchas.md](references/gotchas.md) — errores ya cometidos una vez, no los repitas.
- [references/coverage.md](references/coverage.md) — qué niveles/municipios tienen geometría real hoy (para saber qué JOIN va a devolver NULL).
- [references/cross_db_medfut.md](references/cross_db_medfut.md) — cómo MEDFUT (`import_log_leads.id_geo`) se resuelve contra `c98_geo`.
- [references/sample_queries.md](references/sample_queries.md) — patrones de query ya probados.

## Resumen de una línea por tema

- **Modelo:** dimensional en cadena, no adjacency-list. `dim_region → dim_departamento →
  dim_subregion → dim_municipio → dim_zona → dim_comuna → dim_barrio`, cada tabla con FK real
  al padre inmediato y `UNIQUE(padre, nombre)`.
- **`geo_geometry`:** tabla aparte, FK polimórfica `tipo`/`id_tipo` (SIN constraint de motor —
  0 huérfanos verificados pero no garantizado por MariaDB). `geom MULTIPOLYGON SRID 4326`,
  `resolution ENUM('low','mid','high')`.
- **`C98_MEDFUT.dim_geo`** (BD renombrada 2026-07-30, antes `medfut`) **está deprecada.** Si ves
  código que la referencia como fuente activa, es un bug — todo debe leer de `c98_geo`.
- **Cross-DB:** `C98_MEDFUT.import_log_leads.id_geo VARCHAR(15)` se resuelve contra `c98_geo` con
  `LEFT(id_geo, N)` según longitud (2/5/8/11/15 = departamento/municipio/zona/comuna/barrio).
- **Encoding:** históricamente hubo mojibake UTF-8↔CP850 en `nombre` (491 filas, ya corregido).
  Vigilar en cargas nuevas, especialmente desde Excel/CSV en Windows.
- **Geoespacial:** MariaDB no soporta `ST_Union` como agregación GROUP BY, solo binaria — las
  uniones (zona/comuna/subregión/región derivadas de sus hijos) se calcularon en Python con
  `shapely.ops.unary_union`, no en SQL.
