# Gotchas ya descubiertos — no los repitas

Cada uno de estos costó tiempo de debugging real en sesiones anteriores. Están documentados en
detalle en `docs/diccionario_datos.md`; aquí solo el resumen accionable.

1. **Mojibake UTF-8↔CP850 en `nombre`.** Patrón: `├¡` en vez de `í`, `├í` en vez de `á`, `├│` en
   vez de `ó`, `├®` en vez de `é`, `├▒` en vez de `ñ`. Afectó 491 filas en 5 de las 7 tablas
   `dim_*` (nunca confirmado limpio en `dim_subregion`/`dim_zona` porque no coincidió el patrón
   buscado, no porque se haya verificado que están limpias). Ya corregido con backup previo, pero
   **vigilar en cualquier carga nueva desde Excel/CSV en Windows** — es el origen típico del bug
   (UTF-8 reinterpretado como Latin-1/CP850 y regrabado).

2. **MariaDB no tiene `ST_Union` como agregación `GROUP BY`.** Solo existe la versión binaria
   (dos geometrías a la vez). Las uniones de zona/comuna/subregión/región a partir de sus hijos
   se hicieron en Python con `shapely.ops.unary_union`, no en SQL.

3. **`pyshp` devuelve `Polygon` en vez de `MultiPolygon`** para shapes de una sola pieza — rompe
   el insert si la columna está declarada `MULTIPOLYGON`. Normalizar (`Polygon` → `MultiPolygon`
   envolviendo en una lista) antes de insertar.

4. **`max_allowed_packet` insuficiente para departamentos grandes.** Amazonas/Vichada (geometría
   grande) no entraban con el default de 1 MB del servidor. Se subió a 256 MB (`SET GLOBAL`).
   Si un insert de geometría falla silenciosamente o con error de paquete, es lo primero a mirar.

5. **Colisión de nombres entre municipios distintos.** "La Candelaria" existe como comuna tanto
   en Bogotá (zona "Centro") como en Medellín (zona "Centrooriente"). Nunca hagas matching
   tabla↔mapa ni ningún join por `nombre` — usa siempre el `id` real de `c98_geo`. Relacionado:
   un `LIKE` de zona-padre confundió "Centro" con "Centrooriente" porque el primero es substring
   literal del segundo.

6. **`array_search(...) ?: 99` en PHP para ordenar regiones** — PHP trata el índice `0` (primera
   posición del array, ej. "Andina") como falsy, así que el operador `?:` lo manda al final por
   error. Comparar explícitamente contra `false`, no usar el atajo `?:` con resultados de
   `array_search`.

7. **`C98_MEDFUT.dim_geo`** (BD renombrada 2026-07-30, antes `medfut`) **está deprecada.** Si algún código PHP todavía la consulta (ej. una versión
   vieja de `c98geo_api.php`), es un bug de otra era del proyecto, no una fuente válida. Además
   esa tabla nunca tuvo barrio/comuna salvo Medellín y arrastraba los 5 duplicados DIVIPOLA del
   Excel original — cualquier conteo que salga de ahí va a estar mal.

8. **Discrepancias de catálogo DIVIPOLA entre `dim_municipio` y la fuente DANE vigente.**
   Ejemplo real: `27086` "Belén de Bajirá" ya no existe en el DANE MGN2025 — fue resuelto como
   litigio territorial y renombrado a `27493` "Nuevo Belén de Bajirá" (Chocó). Si una geometría
   DANE no matchea un código de `dim_municipio`, antes de asumir error de carga, verificar si
   hubo un cambio de código DIVIPOLA reciente (ordenanza/decreto).

9. **Fuentes de "barrio" con cobertura sesgada.** El dataset "Barrio Legalizado" de Bogotá solo
   registra actos de legalización de asentamientos informales — subrepresenta brutalmente las
   localidades centrales/formales (Los Mártires, La Candelaria, Teusaquillo tenían 1 solo barrio
   cada una). Si una distribución de barrios por localidad/comuna se ve muy desbalanceada hacia
   zonas periféricas, sospechar que la fuente no es la genérica de "barrios" sino un subconjunto
   temático (legalización, catastro parcial, etc.) — buscar la fuente catastral completa en su
   lugar (para Bogotá: "Sector Catastral", Datos Abiertos Bogotá / UAECD).
