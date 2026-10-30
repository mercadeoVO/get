# C98_GEO — Briefing de Proyecto

> **Archivo de contexto para Claude Code.**
> Ubicación: `C:\Users\impag\Documents\C98_MEDFUT\geo\CLAUDE.md`
> (movido desde `C:\Users\impag\Documents\C98_GEO\CLAUDE.md` el 2026-07-30 — ver `../MEMORIA.md`)
> Última actualización: 2026-07-30

---

## 0. ⚠️ Estado real (leer esto primero)

**Las Secciones 1-9 de abajo son el briefing ORIGINAL (2026-07-16) — describen el punto de
partida, no el estado actual.** Desde entonces se ejecutaron las Fases 1 a 5 completas y buena
parte de la 6. La fuente de verdad actualizada es **[docs/diccionario_datos.md](docs/diccionario_datos.md)**
y **[docs/procesos.md](docs/procesos.md)** (división del proyecto en procesos con estado real).

Resumen ejecutivo de lo ya resuelto (detalle completo en `diccionario_datos.md`):

- **Modelo de datos:** no se migró a adjacency-list (Opción A del briefing). El esquema real
  encontrado ya era dimensional: 7 tablas `dim_region → dim_barrio` encadenadas por FK real
  (`padre_id`), cada una con `UNIQUE(padre, nombre)`. `geo_geometry` sigue con FK polimórfica
  `tipo`/`id_tipo` sin constraint de motor (0 huérfanos verificados, pero depende de disciplina
  de aplicación, no del engine).
- **H-01 a H-09 del briefing original:** todos resueltos o descartados. Incluye un hallazgo nuevo
  no anticipado (H-09, mojibake UTF-8↔CP850 en `nombre`) ya corregido con backup previo.
- **Geometría real cargada** (Fase 5): departamento 33/33, municipio 1.121/1.123, barrio 2.271
  (Bogotá, Medellín, Cali, Barranquilla, Cartagena — las 5 ciudades >1M hab.), región/subregión/
  zona/comuna derivados por `ST_Union`. Niveles de resolución `low`/`mid`/`high` generados.
  `geo_geometry` total: 5.341 filas.
- **Dashboard sincronizado:** `c98geo_api.php` y `c98geo_map_api.php` migrados a leer 100% de
  `c98_geo` (antes leían por accidente de `C98_GET.dim_geo`, una tabla de otro proceso). Sync
  tabla↔mapa por `id` exacto, no por nombre. Zoom automático por nivel implementado.
- **Pendiente real (Fase 6-7):** FK real/constraint en `geo_geometry`, `SPATIAL INDEX(geom)`,
  vista `v_integridad_geo`, test CI anti-huérfanos, limpieza de las ~7 tablas `*_bkp*`
  acumuladas, decisión de expandir comuna/barrio más allá de las 5 ciudades grandes, seguimiento
  de la reforma territorial de Antioquia (9→17 subregiones, aún no vigente), estado del piloto
  Power BI (`MED/piloto.pbix`, no auditado), y todo lo de demografía/audiencias digitales
  (bloqueado hasta cerrar Fase 6).

**No repitas la Fase 1-5 de reconocimiento** — usa `diccionario_datos.md` como base y valida
puntualmente contra la BD solo lo que vayas a tocar.

---

## 1. Objetivo del proyecto

Construir una **capa geográfica maestra de Colombia** con integridad referencial garantizada, geometría real (polígonos, no solo puntos) y capacidad de exportación multiformato, para luego cruzarla con datos demográficos, electorales y de audiencias digitales.

**Jerarquía objetivo:**

```
Región (5)
  └─ Departamento (33)
       └─ Subregión (~97)
            └─ Municipio (1.123)
                 └─ Zona
                      └─ Comuna / Localidad
                           └─ Barrio
```

**Resultado esperado:** representación gráfica en cualquier nivel de la jerarquía, asociable a múltiples fuentes de datos, descargable en GeoJSON / TopoJSON / CSV / JSON.

---

## 2. Estado actual del entorno

| Componente         | Ubicación                                       | Notas                                |
| ------------------ | ----------------------------------------------- | ------------------------------------ |
| Base de datos      | MySQL `127.0.0.1:3307`, schema `c98_geo`        | Acceso vía phpMyAdmin                |
| Dashboard          | `http://localhost/get/c98geo_dashboard.html` | Consume `c98_geo`                    |
| Excel origen       | `col_divipola_vo.xlsx`                          | Fuente que alimentó la carga inicial |
| Tabla de geometría | `c98_geo.geo_geometry`                          | 1.326 filas                          |

### Estructura conocida de `geo_geometry`

```
id | tipo | id_tipo | resolution | geom | topojson | centroid_lat | centroid_lon | source | updated_at
```

- `tipo` → nivel jerárquico al que pertenece la geometría (departamento, municipio, comuna...)
- `id_tipo` → **FK polimórfica** hacia la tabla del nivel correspondiente
- `source` observado: `"Excel DIVIPOLA - col_divipola_vo 2026-07-10"`
- En la fila inspeccionada: `geom = NULL`, `topojson = NULL`

---

## 3. Hallazgos confirmados (auditoría del Excel origen)

Estos hallazgos salen del análisis de `col_divipola_vo.xlsx`, que es la fuente declarada de la carga. **Deben verificarse contra la BD real como primera tarea.**

### 🔴 Bloqueantes

**H-01 — 5 códigos DIVIPOLA duplicados**
`27250`, `50223`, `50370`, `52418`, `52699`
1.128 filas de municipio con solo 1.123 códigos únicos. Si `geo_municipio` no tiene `UNIQUE` sobre el código, todo `JOIN` de demografía infla cifras.

**H-02 — `geo_geometry` (1.326 filas) no reconcilia con la jerarquía**

```
 33 departamentos
+ 1.123 municipios únicos   = 1.156
+ 68 comunas                = 1.224
─────────────────────────────────────
  geo_geometry reporta      = 1.326
  Δ sin explicar            =   102
```

102 no corresponde a ningún nivel conocido. Hipótesis a descartar: barrios parciales, duplicados por `resolution`, residuos de una carga anterior.

**H-03 — No hay geometría, hay centroides**
`geom` y `topojson` en NULL en la fila inspeccionada. Si es el patrón general, la tabla contiene **puntos, no polígonos** → solo permite mapas de burbujas, no coropletas. Rompe el objetivo del proyecto.

**H-04 — FK polimórfica no enforzable**
MySQL no puede declarar un constraint del tipo "si `tipo='municipio'` entonces `id_tipo` → `geo_municipio.id_mpio`". La integridad de `geo_geometry` es hoy **imposible de garantizar a nivel motor**.
Problema de tipo asociado: `id_tipo` debe albergar `05` (depto), `05001` (municipio) y `109` (barrio). Un `INT` destruye el cero a la izquierda; un `VARCHAR` permite que `'5'` y `'05'` coexistan como entidades distintas.

### 🟠 Importantes

**H-05 — Valores centinela de Excel en la BD**
`region = '#N/A'` presente en `GEO_MPIO`. También `#REF!` en columnas huérfanas de `GEO_DPTOS`. Rompen el rollup Región→Departamento.

**H-06 — Doble jerarquía regional contradictoria**
Valle del Cauca: `REGION = 'Pacífico'` vs `Region2 = 'Andina'`. Hay que elegir una taxonomía canónica.

**H-07 — 33 municipios sin centroide propio**
Heredan lat/lon del departamento. En el mapa se apilan exactamente sobre la capital.

**H-08 — Cobertura sub-municipal casi inexistente**

- Comunas/Localidades: solo **4 ciudades** (Bogotá, Medellín, Cali, Barranquilla) = 68 registros
- Barrios: solo **Medellín** = 332 registros

### Conteos de control

| Nivel               | Esperado | Notas                                           |
| ------------------- | -------- | ----------------------------------------------- |
| Regiones            | 5        | Andina, Caribe, Pacífica, Orinoquía, Amazónica  |
| Departamentos       | 33       | 32 + Bogotá D.C.                                |
| Subregiones         | ~97      | Verificar si es catálogo oficial o ad-hoc       |
| Municipios          | 1.123    | únicos DIVIPOLA (1.128 filas en Excel = 5 dups) |
| Comunas/Localidades | 68       | cobertura parcial                               |
| Barrios             | 332      | solo Medellín                                   |

---

## 4. Decisión pendiente de arquitectura

|                       | **Opción A — Consolidar (recomendada)**                                                                                                 | **Opción B — Blindar el polimorfismo**                                                   |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Modelo                | Una `dim_geografia` única con todos los niveles y `parent_id` self-referencing (adjacency list). `geo_geometry.id_geo` → FK real única. | Se mantiene `tipo`/`id_tipo`, con `CHECK` constraints, triggers y job de reconciliación. |
| Integridad            | ✅ Garantizada por el motor                                                                                                             | ⚠️ Depende de que el job corra                                                           |
| Consultas jerárquicas | `WITH RECURSIVE` nativo                                                                                                                 | Joins manuales por nivel                                                                 |
| Costo                 | Migración + refactor del dashboard                                                                                                      | Bajo                                                                                     |
| Cuándo                | Por defecto. Es una jerarquía pura, el árbol le queda perfecto.                                                                         | Solo si el dashboard no se puede tocar                                                   |

**Esta decisión debe tomarse en la Fase 2, antes de escribir cualquier migración.**

---

## 5. Plan de fases

### FASE 1 — Reconocimiento _(primera sesión de Code)_

- [ ] Conectar a `c98_geo` en solo lectura
- [ ] Inventario: tablas, columnas, tipos, FKs declaradas, índices
- [ ] Perfil de `geo_geometry` agrupado por `tipo`
- [ ] **Reconciliar las 1.326 filas** — resolver H-02
- [ ] Confirmar o descartar H-03 (¿hay polígonos o solo puntos?)
- **Entregable:** `docs/diccionario_datos.md` con el schema real

### FASE 2 — Diagnóstico de integridad

- [ ] Geometrías huérfanas (`id_tipo` apunta a entidad inexistente), por cada `tipo`
- [ ] Entidades sin geometría (el inverso — hueco de cobertura)
- [ ] Cadena jerárquica rota en cada eslabón
- [ ] Coherencia DIVIPOLA: `LEFT(LPAD(id_mpio,5,'0'),2) = LPAD(id_dpto,2,'0')`
- [ ] Cuantificar H-01 en la BD
- [ ] Barrer valores centinela (H-05)
- [ ] **Decidir Opción A vs B**
- **Entregable:** `docs/informe_integridad.md` con conteo exacto de violaciones

### FASE 3 — Validación geoespacial

- [ ] Bounding box Colombia: `lat -4.30 .. 13.60`, `lon -82.00 .. -66.80`
      _(cubre San Andrés ~12.55/-81.72 y Malpelo ~4.00/-81.61)_
- [ ] Longitud positiva o latitud >15 → imposible en Colombia
- [ ] Lat/Lon intercambiadas: `centroid_lat BETWEEN -82 AND -66` → hubo swap en la carga
- [ ] Coordenadas `(0,0)` o `lat = lon`
- [ ] Centroides idénticos compartidos por varias entidades → hijos heredando el punto del padre (H-07)
- [ ] Precisión decimal: **<4 decimales = insuficiente para barrio** (~11 m de error)
- **Entregable:** `docs/centroides_a_corregir.csv` + fuente de reemplazo

### FASE 4 — Migración del modelo

- [ ] `CREATE TABLE geo_geometry_bkp AS SELECT * FROM geo_geometry;` **antes de tocar nada**
- [ ] Crear `dim_geografia` con `UNIQUE(nivel, codigo)` y `FK parent_id`
- [ ] Deduplicar los 5 DIVIPOLA de H-01
- [ ] Normalizar códigos a `CHAR` con `LPAD` fijo por nivel
- [ ] Migrar `geo_geometry` a FK real
- **Entregable:** migración versionada + rollback

### FASE 5 — Poblar geometría real

- [ ] **Departamento + Municipio**: shapefile DIVIPOLA del DANE → GeoJSON → `ST_GeomFromGeoJSON` → `geom`
- [ ] **Comuna/Localidad**: datos abiertos por alcaldía (IDECA Bogotá, GeoMedellín, Cali en Cifras, Barranquilla)
- [ ] **Barrio**: catastro municipal; fallback OSM Overpass (`admin_level=10`)
- [ ] Declarar SRID explícito en la columna
- [ ] Generar TopoJSON **por nivel** (ver Optimización #3)
- [ ] **Recalcular `centroid_lat/lon` desde el polígono** vía `ST_Centroid` — nunca desde lista externa
- [ ] Poblar `resolution` con 2-3 niveles de simplificación
- **Entregable:** cobertura de polígonos ≥95% en depto y municipio

### FASE 6 — Enforcement continuo

- [ ] FKs reales + `UNIQUE` + `SPATIAL INDEX(geom)`
- [ ] Vista `v_integridad_geo` que exponga violaciones en tiempo real
- [ ] CI: test que falla si aparece un huérfano
- **Solo aquí volvemos a demografía**

### FASE 7 — Demografía y población digital _(bloqueada hasta Fase 6)_

- Demografía: `DEMO_CO_2025_GENAGE`, `censoelectoral_2025` — ya existen en el Excel
- Audiencias digitales: `TEMAS_ANA_CO`, `TEMAS_MPIO_SEC` — activo infrautilizado
- Población digital: **no existe hoy** → ONTIC/MinTIC, datos.gov.co

---

## 6. Fuentes externas mapeadas

### Geografía — API REST

| Fuente       | Endpoint                                      | Uso                                                                                                                                                      |
| ------------ | --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| API Colombia | `https://api-colombia.com/api/v1/Department`  | Departamentos + coordenadas                                                                                                                              |
| API Colombia | `https://api-colombia.com/api/v1/City`        | Ciudades con `departmentId`                                                                                                                              |
| API Colombia | `https://api-colombia.com/api/v1/UrbanCenter` | **Cabeceras municipales y centros poblados con lat/lon y código** — llena huecos                                                                         |
| API Colombia | `https://api-colombia.com/api/v1/mcp`         | **Servidor MCP** (Streamable HTTP, stateless, sin auth, read-only). Conectable directo como tool en Claude Code — evita escribir el cliente REST a mano. |

Sin autenticación. ~2M requests/mes de tráfico, tratar con rate limiting cortés.

### Geografía — Cartografía oficial (polígonos)

| Fuente                                              | Tipo                                  | Notas                                                                                                                                             |
| --------------------------------------------------- | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| **DANE — Marco Geoestadístico Nacional / DIVIPOLA** | Shapefile                             | Fuente canónica para depto + municipio. Prioridad #1.                                                                                             |
| `colombiaenmapas.gov.co` (IGAC/ICDE)                | **Geoservicios OGC**: WMS / WFS / WCS | No es REST simple. WFS = descarga vectorial por capa. Consumir con `owslib` en Python o QGIS. Requiere identificar la capa exacta en el geovisor. |
| IDECA (Bogotá) / GeoMedellín / alcaldías            | GeoJSON / Shapefile                   | Comunas, localidades, barrios. Vía más simple que IGAC.                                                                                           |
| **OSM Overpass API**                                | GeoJSON                               | Barrios (`admin_level=10`) para municipios sin fuente oficial. Buena cobertura urbana en Colombia.                                                |

⚠️ Los ImageServer del IGAC usan **ArcGIS REST API** (parámetros `query`, `exportImage`, `mosaicRule`) — **no son estándar OGC**, QGIS no puede hacer queries directas sobre ellos.

### Población digital

| Fuente                                  | Granularidad                       | Notas                                                                  |
| --------------------------------------- | ---------------------------------- | ---------------------------------------------------------------------- |
| ONTIC/MinTIC — accesos fijos a Internet | Departamento, trimestral           | Registro administrativo HECAA                                          |
| ONTIC/MinTIC — accesos móviles          | Nacional/departamental, trimestral |                                                                        |
| Colombia TIC — Atlas de acceso fijo     | Más granular, trimestral           |                                                                        |
| datos.gov.co — dataset `n48w-gutb`      | Municipio (verificar)              | "Internet Fijo — Accesos por tecnología y segmento". API Socrata/SODA. |
| DANE — Indicadores TIC en Hogares       | Encuesta                           | Uso real, no solo accesos                                              |

---

## 7. Convenciones del proyecto

### Códigos

- **DIVIPOLA es la llave universal.** `CHAR`, nunca `INT`.
- Departamento → 2 dígitos, `LPAD(x,2,'0')` → `05`, `08`, `11`
- Municipio → 5 dígitos, `LPAD(x,5,'0')` → `05001`, `11001`
- **Invariante:** `LEFT(id_mpio,2) = id_dpto`. Sin excepción.

### Geoespacial

- Colombia oficial es **MAGNA-SIRGAS = EPSG:4686**. Google/Mapbox usan **WGS84 = EPSG:4326**.
- La diferencia es sub-métrica → irrelevante para marketing, **crítica si mezclas capas sin declarar SRID**: `ST_Contains` y cálculos de distancia salen mal.
- **Declarar SRID explícito siempre:** `geom GEOMETRY SRID 4326 NOT NULL`
- Precisión mínima por nivel: depto ≥2 decimales · municipio ≥3 · comuna ≥4 · barrio ≥5

### Datos

- Prohibido que un valor centinela de Excel (`#N/A`, `#REF!`, `#VALUE!`, `'null'`, `''`) llegue a la BD. Se convierte a `NULL` en la carga.
- Todo supuesto o número hardcodeado se documenta donde el lector lo va a ver.
- Ninguna sentencia destructiva sin backup previo.

---

## 8. Optimización adicional

1. **`resolution` es tu mejor amiga y probablemente está vacía.** Guarda el mismo polígono en 2-3 resoluciones (Douglas-Peucker a ~0.01°, ~0.001°, full). El dashboard sirve el simplificado en zoom nacional y el full en zoom barrio. Sin esto, 1.123 polígonos municipales en el navegador tardan segundos.

2. **TopoJSON debe ser por colección, no por fila.** TopoJSON comprime **compartiendo arcos entre polígonos vecinos**. Un TopoJSON por municipio aislado no comprime nada — pierde todo el beneficio. Debería ser **un archivo por nivel** (uno con los 33 dptos, uno con los 1.123 municipios). **Si la columna `topojson` es per-row, el diseño está mal desde el origen** → reemplazar por materialización en disco/CDN.

3. **`TEMAS_ANA_CO` / `TEMAS_MPIO_SEC` son un activo infrautilizado.** Ya contienen tamaños de audiencia por interés × género × rango de edad × municipio (formato tipo Meta Ads). Cruzado con geo + población digital, permite construir **audiencias geo-segmentadas priorizando zonas con alta penetración digital real** → reduce CPM desperdiciado en zonas sin conectividad.

4. **Modelo estrella antes de Power BI**, no tablas planas: `dim_geografia`, `dim_tiempo`, `fact_demografia`, `fact_digital`, `fact_intereses`. Ahorra DAX complicado de `CALCULATE`+`FILTER` cruzando tres dominios.

5. **Considerar PostGIS.** MySQL hace geoespacial, PostGIS lo hace bien. Si el proyecto va a crecer a análisis espacial real (buffers, intersecciones, tesselación de audiencias), la migración sale más barata ahora que en la Fase 5.

---

## 9. Primera instrucción para Claude Code

```
Lee CLAUDE.md. Ejecuta la FASE 1 en solo lectura contra
mysql://127.0.0.1:3307/c98_geo.

Prioridad:
  1. Resolver H-02: reconciliar las 1.326 filas de geo_geometry
  2. Confirmar o descartar H-03: ¿hay polígonos o solo centroides?
  3. Producir docs/diccionario_datos.md

No escribas nada en la BD. No propongas migración hasta
tener el diccionario completo.
```

**Credenciales:** en `.env`, usuario con **SELECT** únicamente hasta pasar Fase 3.

**Referencia:** el script `c98geo_auditoria.sql` (4 bloques, ~30 consultas de solo lectura) cubre las Fases 1-3. Los nombres de tabla del Bloque 3 son placeholders — ajustar tras el Bloque 0.
