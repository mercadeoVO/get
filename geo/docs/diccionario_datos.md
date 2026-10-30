# Diccionario de datos — `c98_geo` (estado real, verificado en BD)

> Generado en sesión de reconocimiento FASE 1. Fuente: conexión directa de solo lectura a
> `127.0.0.1:3307/c98_geo` (MariaDB 10.4.32) vía `mysql-connector-python`, complementada con
> `information_schema`. **No se tocó la base `C98_GET`** (fuera de alcance).
>
> ⚠️ Este diccionario **reemplaza las suposiciones de la Sección 2/3 del `CLAUDE.md` original**.
> El esquema real ya no es el modelo plano `GEO_DPTOS` / `GEO_MPIO` + `geo_geometry` polimórfico
> descrito ahí — alguien ya migró a un modelo dimensional. Ver Hallazgo H-09 más abajo.

## 1. Inventario de tablas

| Tabla | Filas | PK | Notas |
|---|---|---|---|
| `dim_region` | 5 | `id` INT AUTO_INCREMENT | |
| `dim_departamento` | 33 | `id` VARCHAR(2) | FK → `dim_region.id` (nullable, pero 0 nulos reales) |
| `dim_subregion` | 143 | `id` VARCHAR(5) | FK → `dim_departamento.id` |
| `dim_municipio` | 1.123 | `id` VARCHAR(5) | FK → `dim_departamento.id`, FK → `dim_subregion.id` (nullable) |
| `dim_zona` | 2.257 | `id` VARCHAR(8) | FK → `dim_municipio.id` |
| `dim_comuna` | 68 | `id` VARCHAR(11) | FK → `dim_zona.id` |
| `dim_barrio` | 329 | `id` VARCHAR(15) | FK → `dim_comuna.id` |
| `geo_geometry` | 1.326 | `id` BIGINT AUTO_INCREMENT | FK polimórfica `tipo`/`id_tipo` (no enforzable a nivel motor) |

**Jerarquía real:** `dim_region → dim_departamento → dim_subregion → dim_municipio → dim_zona → dim_comuna → dim_barrio`.
Es una cadena de 7 niveles con FK real en cada eslabón (excepto `geo_geometry`), no una jerarquía
adjacency-list única como planteaba la "Opción A" del briefing — es una variante intermedia: **cada
nivel es su propia tabla, pero con FK real al padre inmediato**. Consultas jerárquicas requieren
join en cadena, no `WITH RECURSIVE` sobre una sola tabla.

## 2. Integridad estructural — YA RESUELTA

- **H-01 (duplicados DIVIPOLA):** resuelto. `dim_municipio.id` es PRIMARY KEY → duplicados
  estructuralmente imposibles. 1.123 municipios únicos confirmados.
- **H-04 (FK polimórfica no enforzable):** resuelto **para las tablas de entidad** (`dim_*`).
  Cada nivel tiene FK real declarada y `UNIQUE(padre, nombre)`. Códigos son `VARCHAR` con
  padding correcto (`id_departamento` 2 dígitos, `id_municipio` 5 dígitos) — el problema del
  cero a la izquierda ya no existe.
  **Sigue sin resolver en `geo_geometry`**: `tipo`/`id_tipo` sigue siendo polimórfico, sin FK
  real. Verificado 0 huérfanos hoy (todas las 1.326 filas resuelven contra su tabla de entidad),
  pero la integridad depende de disciplina de aplicación, no del motor.
- **H-05 (valores centinela `#N/A`/`#REF!`):** 0 ocurrencias en columnas `nombre` de las 7
  tablas `dim_*`. Resuelto en la BD actual (puede que se haya limpiado en la migración).
- **H-06 (doble jerarquía regional):** resuelto. Región es única y canónica: 5 regiones, 33/33
  departamentos asignados, 0 nulos. (Andina 11, Caribe 8, Amazonía 6, Orinoquía 4, Pacífico 4).
- **Invariante DIVIPOLA** `LEFT(id_municipio,2) = id_departamento`: **0 violaciones** sobre
  1.123 municipios.
- **Bounding box Colombia** (`lat -4.30..13.60`, `lon -82.00..-66.80`): **0 outliers** en
  `centroid_lat/lon`. **0 centroides** compartidos por más de 3 entidades (no hay señal masiva
  de "hijo heredando punto del padre").

## 3. H-03 — CONFIRMADO: no hay polígonos, solo centroides

`geo_geometry.geom` (tipo `multipolygon`) y `geo_geometry.topojson` (tipo `longtext`) están
**NULL en el 100% de las 1.326 filas**, sin excepción, en los 4 niveles poblados. La columna
está lista estructuralmente (tipo correcto declarado) pero **nunca se cargó geometría real**.
Hoy el proyecto solo puede producir mapas de puntos/burbujas, no coropletas. Esto bloquea el
objetivo central del proyecto tal como está descrito en la Sección 1 del briefing.

También confirmado: `resolution` (enum `low`/`mid`/`high`) tiene **el 100% de las filas en
`'mid'`** — nunca se pobló `low` ni `high` pese a que el diseño ya lo soporta (ver Optimización
#1 del briefing).

## 4. H-02 — Reconciliación de `geo_geometry` (1.326 filas)

Cobertura real por nivel (entidades con geometría / total del nivel):

| Nivel | Con geometría | Total | Cobertura |
|---|---|---|---|
| región | 0 | 5 | 0% |
| departamento | 33 | 33 | 100% |
| subregión | 0 | 143 | 0% |
| municipio | 1.091 | 1.123 | 97% (32 sin geometría propia) |
| zona | 0 | 2.257 | 0% |
| comuna | 68 | 68 | 100% |
| barrio | 134 | 329 | 41% |

`33 + 1.091 + 68 + 134 = 1.326` — **cuadra exacto**. El delta de 102 filas sin explicar que
mencionaba el briefing original (H-02) ya no existe en el estado actual: la aritmética reconcilia
perfectamente porque `dim_region`, `dim_subregion` y `dim_zona` **no tienen ninguna fila en
`geo_geometry`** — no es que sobren 102 filas huérfanas, es que 3 niveles completos de la
jerarquía nunca recibieron geometría/centroide.

**0 huérfanos** en `geo_geometry` (cada `id_tipo` resuelve contra su tabla de entidad
correspondiente).

## 5. H-09 (nuevo) — Corrupción de encoding (mojibake) en `nombre` — ✅ CORREGIDO

> **Actualización post-FASE1:** corregido. Se aplicó `UPDATE` sobre las 491 filas afectadas
> (reversión `nombre.encode('cp850').decode('utf-8')`, patrón confirmado: bytes UTF-8
> reinterpretados como CP850 y regrabados como UTF-8). Backup previo creado como
> `dim_region_bkp`, `dim_departamento_bkp`, `dim_municipio_bkp`, `dim_comuna_bkp`,
> `dim_barrio_bkp`. Verificado: 0 residuos de corrupción, conteos de filas intactos en las 7
> tablas. Detalle original de la corrupción abajo, para referencia.


No estaba en el briefing original. Patrón: caracteres acentuados en español fueron
re-codificados incorrectamente (cadena típica UTF-8 → Latin-1 → UTF-8), produciendo secuencias
como `├¡` en vez de `í`, `├í` en vez de `á`, `├│` en vez de `ó`, `├®` en vez de `é`, `├▒` en vez
de `ñ`. Confirmado a nivel de bytes (no es un artefacto de terminal).

| Tabla | Filas afectadas | % |
|---|---|---|
| `dim_region` | 3 / 5 | 60% |
| `dim_departamento` | 12 / 33 | 36% |
| `dim_municipio` | 382 / 1.123 | 34% |
| `dim_comuna` | 21 / 68 | 31% |
| `dim_barrio` | 73 / 329 | 22% |
| `dim_subregion` | 0 / 143 | — (sin coincidencias del patrón buscado; no confirma que esté limpia) |
| `dim_zona` | 0 / 2.257 | — (ídem) |

**Riesgo:** cualquier exportación directa desde la BD (GeoJSON, CSV, JSON, Power BI) hereda esta
corrupción. Se observó que el dashboard (`c98geo_dashboard.html` / su API) muestra los nombres
correctamente — es decir, hay una capa de aplicación que compensa el problema en tiempo de
lectura en vez de que el dato esté limpio en origen. Eso enmascara el problema para quien mira
el dashboard, pero no para nadie que consuma la BD directamente.

## 5-bis. H-03 — ACTUALIZACIÓN: polígonos reales cargados (departamento + municipio)

> Fuente: DANE, Marco Geoestadístico Nacional 2025 (WGS84), descarga oficial desde
> `geoportal.dane.gov.co` (sin login). Departamento vía shapefile
> (`MGN2025_DPTO_POLITICO.zip`), Municipio vía GeoJSON
> (`MGN2025_ADM_MPIO_GRAFICO_(geojson).zip`, 273 MB descomprimido). CRS de origen:
> `OGC:CRS84`/`WGS_1984` — mapea directo a EPSG:4326, sin transformación de datum.
> Backup previo: `geo_geometry_bkp` (1.326 filas, snapshot pre-carga).

- **Departamento: 33/33 (100%)** con `geom` real (`MULTIPOLYGON`, SRID 4326).
- **Municipio: 1.121/1.123 (99,8%)** con `geom` real. `resolution` sigue en `'mid'` (pendiente
  punto 5 del plan: generar variantes `low`/`high`).
- `centroid_lat`/`centroid_lon` de ambos niveles **recalculados desde el polígono** vía
  `ST_Centroid(geom)`, reemplazando los valores previos (que eran centroides sueltos, no
  derivados de geometría). 0 outliers de bounding box tras el recálculo.
- Se detectó un bug de `pyshp`: para shapes de una sola pieza devuelve GeoJSON tipo `Polygon`
  en vez de `MultiPolygon`, incompatible con la columna `MULTIPOLYGON`. Se normalizó
  envolviendo `Polygon` → `MultiPolygon` antes de insertar.
- `max_allowed_packet` del servidor estaba en 1 MB (insuficiente para departamentos grandes
  como Amazonas/Vichada) — se subió a 256 MB a nivel de servidor (`SET GLOBAL`).

**2 municipios de `dim_municipio` sin geometría DANE — discrepancia de catálogo DIVIPOLA, no
error de carga:**

| Código en `dim_municipio` | Nombre | Situación |
|---|---|---|
| `27086` | Belén de Bajirá | El DANE MGN2025 ya no lo publica bajo este código. Aparece como **`27493` "Nuevo Belén de Bajirá"** (Chocó), por Ordenanza 180/2023 y Decreto 0284/2023 — resolución del litigio territorial histórico Antioquia/Chocó a favor de Chocó bajo código nuevo. `dim_municipio` sigue usando el código anterior. |
| `94663` | Mapiripán | Sin geometría en el archivo DANE descargado; no se investigó la causa específica. |

**Decisión tomada por el usuario: migrar `27086` → `27493`.** Ejecutado:
1. Se creó `dim_municipio.27493` "Nuevo Belén de Bajirá" (mismo `id_departamento`='27' e
   `id_subregion`='27S01' que tenía `27086`).
2. Se crearon sus 2 `dim_zona` hijas (`27493Z01` urbana, `27493Z02` rural), mismos nombres que
   las de `27086`.
3. Se cargó la geometría real del DANE MGN2025 para `27493` (ya la teníamos descargada) y se
   recalculó su centroide.
4. Se eliminó `27086`: sus `dim_zona` hijas, su fila en `geo_geometry` (tipo='municipio') y el
   registro en `dim_municipio`.
5. Se recalculó `subregion` `27S01` (unión de sus municipios) para reflejar el cambio.

Backups previos: `dim_municipio_bkp`, `dim_zona_bkp` (snapshot completo pre-migración).
Verificado: 1.123 municipios (conteo intacto), 2.257 zonas (conteo intacto), 0 zonas huérfanas,
0 filas de `geo_geometry` apuntando a un municipio inexistente.

## 5-ter. H-08 — ACTUALIZACIÓN: barrio real cargado para las 4 ciudades >1M habitantes accesibles

> Alcance decidido con el usuario: ciudades con más de 1.000.000 de habitantes (Bogotá 7,93M,
> Medellín 2,62M, Cali 2,28M, Barranquilla 1,32M, Cartagena 1,05M — fuente: proyecciones DANE).
> Se descartó la cobertura nacional vía OSM Overpass tras prueba de factibilidad: Antioquia
> completa (33 municipios) solo tiene 1 relación `admin_level=10` en todo OSM — cobertura
> insuficiente para uso real fuera de Medellín.

| Ciudad | Barrios cargados | Fuente | Notas |
|---|---|---|---|
| Medellín | 330/332 | Local `MED/medellin_barrios_topo_v2.json` (TopoJSON, decodificado manualmente) | 2 zonas especiales sin comuna (`SN01`/`SN02`) excluidas. 330/332 machearon por (comuna, nombre normalizado) contra `dim_barrio` ya existente — 0 entidades nuevas creadas. |
| Bogotá | 1.727 | Datos Abiertos Bogotá — dataset "Barrio Legalizado" (Esri JSON, CRS `CarMAGBOG`) | Reproyectado a EPSG:4326 con `pyproj`. 1.775 polígonos de legalización agrupados y unidos (`ST_Union`/`shapely.unary_union`) por `(CODIGO_LOCALIDAD, NOMBRE)` → 1.727 barrios únicos. Localidad mapeada por tabla oficial de 20 códigos DANE/Bogotá. |
| Barranquilla | 186/191 | MapServer propio de la Alcaldía (`miciudad.barranquilla.gov.co/gis/rest/services/ordenamiento/unidadesadministrativas`), hallado inspeccionando las llamadas de red de su dashboard público — el dataset federado en datos.gov.co estaba muerto (filas vacías desde 2021) | 5 barrios sin `localidad` asignada en la fuente (corregimientos periféricos: Juan Mina, Santa Isabel, etc.) quedaron sin comuna. |
| Cartagena | 213 | Cartagena Cómo Vamos (ArcGIS Hub) | **16 comunas nuevas creadas** en `dim_comuna` bajo `id_zona='13001Z01'` (Unidades Comuneras de Gobierno 1-15 + 20) — Cartagena no tenía ninguna comuna previa. Nombres puestos como `"Unidad Comunera de Gobierno N"` — son un placeholder descriptivo, la fuente no trae nombre oficial por UCG. |
| Cali | 342/342 | WFS del mismo servidor IDESC, **workspace correcto `dapm` en vez de `idesc`** (`dapm:pdt_dpa_barrios_sectores`) | Resuelto en sesión posterior: la capa vieja (`idesc:mc_barrios`) efectivamente no existe, pero el servidor expone cientos de capas en otros workspaces (`dapm`, `catastro`, `pot_2014`, etc.) — la correcta es `dapm:pdt_dpa_barrios_sectores` (barrios) y `dapm:pdt_dpa_comunas` (22 comunas, confirma exacto el conteo ya existente en `dim_comuna`). |

**Resultado:** `geo_geometry` tipo `barrio` pasó de 134 a **2.455 filas con polígono real** (antes
solo Medellín tenía algo, y parcial). Centroides recalculados vía `ST_Centroid`. 0 outliers de
bounding box. Backups previos: `dim_comuna_bkp`, `dim_barrio_bkp`, `geo_geometry_bkp2`.

## 5-quater. H-02/H-03 — CIERRE: cadena completa de uniones ascendentes (punto 3 del plan)

> Ejecutado con `shapely.ops.unary_union` en Python (MariaDB no tiene `ST_Union` como función
> de agregación por GROUP BY, solo binaria) + reparación de topología vía `.buffer(0)` en
> geometrías inválidas antes de unir. Centroides recalculados vía `ST_Centroid` en cada nivel.
> Backup previo: `geo_geometry_bkp3`.

| Nivel | Cobertura final | Método |
|---|---|---|
| región | 5/5 (100%) | `ST_Union` de sus departamentos (fuente DANE) |
| departamento | 33/33 (100%) | Fuente directa DANE MGN2025 |
| subregión | 143/143 (100%) | `ST_Union` de sus municipios (fuente DANE) |
| municipio | 1.121/1.123 (99,9%) | Fuente directa DANE MGN2025 (faltan `27086`/`94663`, ver H-03 arriba) |
| zona | 15/2.257 (0,7%) | `ST_Union` de sus comunas — solo existen comunas con geometría en Bogotá/Medellín/Barranquilla/Cartagena urbanas; el resto de zonas (rurales, resto del país) no tiene comuna/barrio definido en el esquema, así que no hay de qué derivar |
| comuna | 61/68 (89,7%, sube a 84 filas por comunas nuevas de Cartagena) | `ST_Union` de sus barrios — las 22 comunas de Cali quedan sin geometría (sin fuente de barrio) |
| barrio | 2.455 (100% de lo cargado) | Fuentes directas (DANE no cubre este nivel) — ver H-08 arriba |

**`geo_geometry` total: 3.857 filas, 0 outliers de bounding box en toda la tabla.**

> **Actualización — Cali resuelto:** se encontró la fuente correcta (ver H-08 arriba). Cali
> aportó 342 barrios y sus 22 comunas ya tenían geometría por unión. Cifras finales tras
> incorporar Cali: `comuna` 83/84 (100% salvo Sumapaz, localidad rural de Bogotá sin barrios
> legalizados — hueco esperado, no error), `zona` 20/2.257 (todas las zonas urbanas de las 5
> ciudades grandes). `geo_geometry` total: **5.688 filas, 0 outliers de bounding box.**

El techo de cobertura en `zona`/`comuna` ahora es el resto del país fuera de las 5 ciudades
grandes (sin comuna/barrio en el esquema en absoluto, por diseño — ver H-08 original). Subir
más allá requiere decidir expandir `dim_comuna`/`dim_barrio` a otros municipios (alcance no
cubierto en esta sesión).

## 5-quinquies. Optimización #1 del briefing — niveles de resolución generados

> Ejecutado con `shapely.simplify(tolerance, preserve_topology=True)` (Douglas-Peucker) sobre
> las geometrías `high` (precisión completa, fuente DANE). Backup previo: `geo_geometry_bkp4`.

| Nivel | `low` (tol. 0.01°) | `mid` (tol. 0.001°) | `high` (completa) |
|---|---|---|---|
| región | ✅ | ✅ | ✅ |
| departamento | ✅ | ✅ | ✅ |
| subregión | ✅ | ✅ | ✅ |
| municipio | — (no aporta a zoom nacional con 1.123 polígonos) | ✅ | ✅ |
| zona / comuna / barrio | — | — | ✅ (solo se ven en zoom cercano, no necesitan simplificación) |

Ejemplo real (Antioquia, departamento `05`): **44.385 vértices en `high` → 2.313 en `mid` →
346 en `low`** (~99% de reducción en el nivel más simplificado). `geo_geometry` pasó de 3.857 a
**5.341 filas**. 0 filas sin centroide, 0 outliers de bounding box.

## 6. Discrepancia dashboard vs. BD — RESUELTA: `c98geo_api.php` migrado a `c98_geo`

> Causa raíz encontrada en sesión posterior: `c98geo_api.php` nunca consultaba `c98_geo`.
> Leía de `C98_GET.dim_geo`, una tabla plana con columna `nivel` (otro proceso, integrado
> inicialmente pero independiente) que jamás tuvo datos de barrio/comuna salvo para Medellín,
> y cuyos conteos de municipio arrastraban los 5 duplicados DIVIPOLA del Excel original
> (H-01) porque nunca pasó por la deduplicación que sí se aplicó en `c98_geo`.

**Decisión del usuario:** eliminar toda referencia a `C98_GET.dim_geo` del dashboard de
Geografía — `dim_geo` es de otro proceso que estaba integrado por accidente. Todo el módulo
de Geografía (tabla + mapa) debe leer exclusivamente de `c98_geo`, respetando sus FK reales.

**Cambio aplicado:** `c98geo_api.php` reescrito por completo. Antes construía el árbol a mano
agrupando por nombre de texto desde `dim_geo` (con un mapeo `$regionDptos` hardcodeado
depto→región). Ahora:
- Trae las 7 tablas de `c98_geo` (`dim_region` → `dim_barrio`) completas.
- Arma el árbol navegando las FK reales (`id_region`, `id_departamento`, `id_subregion`,
  `id_municipio`, `id_zona`, `id_comuna`) — sin mapeos hardcodeados ni matching de texto.
- Ya no depende de `config.php` (que apunta a `C98_GET`) — conexión propia a `c98_geo`.

**KPIs verificados exactos tras el cambio** (antes vs. ahora, BD real en ambos casos):

| Métrica | Antes (C98_GET/dim_geo) | Ahora (c98_geo) |
|---|---|---|
| Municipios | 1.128 | **1.123** ✅ |
| Zonas | 19 | **2.257** ✅ |
| Comunas | 68 | **84** ✅ (incluye las 16 nuevas de Cartagena) |
| Barrios | 330 | **2.793** ✅ |

Efecto colateral positivo: Bogotá, Cali, Barranquilla y Cartagena — que en `C98_GET` nunca
tuvieron barrio/comuna cargado — ahora despliegan su jerarquía completa en la tabla, igual
que ya la mostraba el mapa desde antes.

`C98_GET` no se modificó en ningún momento — solo se dejó de leer de ahí para este módulo.

## 7. Cobertura sub-municipal (H-08, confirma briefing)

- `dim_comuna`: 68 filas — mismas 4 ciudades que anticipaba el briefing (cobertura parcial).
- `dim_barrio`: 329 filas — dominado por Medellín, igual que documentaba el briefing.

## 8. `dim_subregion` = 143 — VALIDADO, no es un error

Revisadas las 143 filas agrupadas por departamento contra fuentes oficiales conocidas. El
catálogo es coherente y no muestra señales de duplicación ni error:

- **Antioquia (9):** Bajo Cauca, Magdalena Medio, Nordeste, Norte, Occidente, Oriente,
  Suroeste, Urabá, Valle de Aburrá — coincide exacto con la división oficial vigente desde
  Ordenanza 41 de 1975 (confirmado por fuente externa).
- **Boyacá (13) y Cundinamarca (15):** coinciden con el número de provincias oficiales de cada
  departamento (Cundinamarca: Almeidas, Alto Magdalena, Bajo Magdalena, Gualivá, Guavio,
  Magdalena Centro, Medina, Oriente, Rionegro, Sabana Centro, Sabana Occidente, Soacha,
  Sumapaz, Tequendama, Ubaté — 15 exactas).
- **Santander (6):** Comunera, García Rovira, Guanentá, Mares, Soto, Vélez — provincias
  oficiales santandereanas.
- **Córdoba (7), Sucre (5), Bolívar (6), Nariño (7), Norte de Santander (6), Cauca (5), Caldas
  (6), Tolima (6), Huila (4), Cesar (4), Magdalena (5), Meta (4), Quindío (5), Risaralda (3),
  Atlántico (4), La Guajira (2):** subregiones de planificación departamental, nombres
  plausibles y sin duplicados.
- **Departamentos pequeños con 1 subregión propia (Amazonas, Arauca, Bogotá D.C., Caquetá,
  Casanare, Guainía, Guaviare, Putumayo, San Andrés y Prov., Vaupés, Vichada):** la subregión
  replica el nombre del departamento — es un patrón de modelado para mantener la jerarquía de
  7 niveles uniforme en departamentos sin subdivisión regional oficial, no un error.

**Punto a vigilar (no accionable hoy):** Antioquia tiene en curso una reforma territorial que
pasaría de 9 subregiones a 17 regiones (proyecto de ordenanza departamental reciente, aún sin
confirmar vigencia legal plena al momento de esta sesión). Cuando esa ordenanza quede en firme,
`dim_subregion` para Antioquia necesitará actualizarse — dejar seguimiento para una futura
sesión, no aplicar el cambio ahora sobre una reforma todavía no consolidada.

## 9. Auditoría de Bogotá — datos correctos, fragmentación visual explicada y corregida

> Disparado por observación visual del mapa: los polígonos de `zona` de Bogotá se veían
> "dispersos"/moteados en vez de bloques sólidos.

**Verificado como correcto (no era un bug):**
- `dim_comuna.tipo='localidad'` para las 20 localidades de Bogotá — ya estaba bien desde antes
  de esta sesión.
- Agrupación en 4 `zona` (Centro/Norte/Occidente/Sur) preexistente, geográficamente coherente
  por cuadrante real de la ciudad (ej. Norte = Usaquén+Chapinero+Suba+Barrios Unidos+Teusaquillo).
- 1.725 barrios repartidos entre las 20 localidades, suma exacta, sin huérfanos. Distribución
  plausible (Bosa 311, Suba 258, Ciudad Bolívar 204 en el extremo alto; La Candelaria,
  Teusaquillo, Los Mártires con 1 cada una — coherente con que son las localidades centrales
  más pequeñas). Sumapaz con 0 barrios, ya documentado como localidad rural sin barrios
  legalizados.
- Encoding de nombres de localidades (Usaquén, Antonio Nariño, etc.) confirmado limpio a nivel
  de bytes — otro falso positivo de la consola de Windows, no corrupción real.

**Causa real de la dispersión visual — CORREGIDA:**
Cada polígono de `zona`/`comuna` de Bogotá es un `ST_Union` de sus barrios hijos (fuente:
dataset "Barrio Legalizado" de Bogotá — cientos de actos de legalización independientes). Los
bordes entre barrios vecinos no siempre coinciden exactamente byte-a-byte entre un acto y otro,
dejando micro-gaps que impiden que `ST_Union` los fusione en un bloque sólido — resultado:
`11001Z01` (Norte) tenía **166 fragmentos**, `11001Z04` (Occidente) **227**, pese a agrupar solo
4-6 localidades cada una.

**Corrección aplicada (parche inicial):** `shapely.buffer(+0.0001°).buffer(-0.0001°)` (~11 m)
sobre las geometrías `zona`/`comuna` derivadas. Redujo fragmentos ~50% (Norte 166→91, Occidente
227→93, Sur 145→67), distorsión de área <2%. Backup: `geo_geometry_bkp6`.

## 10. Bogotá — reemplazo de fuente de barrio: "Barrio Legalizado" → "Sector Catastral"

> Disparado por: 1) el buffer de la sección 9 solo mitigaba el síntoma, no la causa — y
> 2) validación puntual de "Los Mártires", que tenía **1 solo barrio** cargado
> (`BARRIO LA PEPITA PREDIOS CARRERA 19A CALLE 10`, un acto de legalización específico, no un
> barrio real).

**Causa raíz real (más allá de los micro-gaps):** el dataset "Barrio Legalizado" que se usó en
la sesión anterior solo registra **actos de legalización de asentamientos informales**. Las
localidades antiguas y consolidadas de Bogotá (formales desde antes de que existiera ese
trámite) casi no aparecen: Sumapaz (0), Los Mártires (1), La Candelaria (1), Teusaquillo (1),
Barrios Unidos (2), Antonio Nariño (4), Tunjuelito (6), Puente Aranda (9), Chapinero (17),
Santa Fe (25) — mientras que las localidades periféricas de desarrollo informal (Bosa 311,
Suba 258, Ciudad Bolívar 204) estaban sobrerrepresentadas. Los huecos que se veían en el mapa
del centro de la ciudad eran **vacíos reales de datos**, no artefactos de render.

**Solución: reemplazo completo de la fuente de barrio de Bogotá.**
- Fuente nueva: "Sector Catastral. Bogotá D.C." (Datos Abiertos Bogotá / UAECD), capa catastral
  oficial completa — 1.230 sectores, CRS `OGC:CRS84` (WGS84, sin reproyección necesaria).
- Como el código de sector catastral (`SCACODIGO`) no corresponde a los códigos DIVIPOLA de
  localidad, se hizo **unión espacial** (point-in-polygon del centroide de cada sector contra
  los polígonos oficiales de las 20 localidades — dataset "Localidad. Bogotá D.C.", Esri JSON,
  también sin reproyección). 1.229/1.230 sectores asignados (1 sin match espacial, descartado).
- 26 nombres duplicados dentro de la misma localidad (mismo barrio partido en varios sectores
  catastrales, ej. "Perdomo Alto" en Ciudad Bolívar) se unieron con `ST_Union` antes de insertar.
- Se **eliminaron los 1.725 barrios viejos** de Bogotá (`Barrio Legalizado`) y se insertaron
  1.203 nuevos (únicos tras deduplicar). Backups: `dim_barrio_bkp3`, `geo_geometry_bkp7`.
- Bug encontrado y corregido en el proceso: mismo patrón de cero a la izquierda que en
  Medellín/Cali — los códigos de localidad 01-09 no matcheaban contra `codigo_local` sin
  padding en `dim_comuna`, causando que esas 9 localidades quedaran en 0 silenciosamente en el
  primer intento (sin error). Corregido normalizando el código antes del lookup.

**Resultado — distribución mucho más pareja y realista:**

| Localidad | Antes (Barrio Legalizado) | Ahora (Sector Catastral) |
|---|---|---|
| Los Mártires | 1 | **20** |
| La Candelaria | 1 | **8** |
| Teusaquillo | 1 | **33** |
| Barrios Unidos | 2 | **38** |
| Antonio Nariño | 4 | **16** |
| Tunjuelito | 6 | **17** |
| Puente Aranda | 9 | **44** |
| Chapinero | 17 | **57** |
| Sumapaz | 0 | **32** |
| Santa Fe | 25 | 30 |

**Bonus — el problema de fragmentación de la sección 9 desapareció por completo, no solo se
mitigó:** al re-derivar `comuna`/`zona` con la geometría catastral (bordes contiguos, sin
gaps de actos de legalización independientes), las 4 zonas de Bogotá pasaron de 166/145/227/19
fragmentos a **1 fragmento sólido cada una**. Ya no hizo falta ni el buffer de cierre de gaps
para este municipio.

**Total nacional de barrios:** 2.793 → **2.271** (2.793 − 1.725 + 1.203). 0 outliers de
bounding box tras el recálculo.

## 11. Sync tabla↔mapa migrado de nombre a id exacto

> Disparado por: "La Candelaria" existe como comuna tanto en Bogotá (zona "Centro") como en
> Medellín (zona "Centrooriente"). El match `LIKE` de la zona-padre confundía ambas porque
> "Centro" es substring literal de "Centrooriente".

Como tabla (`c98geo_api.php`) y mapa (`c98geo_map_api.php`) pasaron a compartir la misma fuente
(`c98_geo`, sección 6), ya no hace falta el matching aproximado por nombre entre dos fuentes
distintas. Cambios:
- `c98geo_api.php` ahora incluye el `id` real de `c98_geo` en cada nivel de su salida JSON
  (región, departamento, subregión, municipio, zona, comuna, barrio).
- `c98geo_map_api.php` ganó un filtro `id` (match exacto, parametrizado).
- El frontend sincroniza tabla→mapa y mapa→tabla por ese `id`, no por nombre — elimina toda la
  clase de bugs por colisión de nombres entre municipios distintos.
- Bug encontrado de paso: el orden de regiones usaba `array_search(...) ?: 99` — PHP trata el
  índice `0` (Andina, primera en la lista) como falsy, así que Andina caía al final. Corregido
  comparando explícitamente contra `false`.

## 12. Zoom automático por nivel (departamento → subregión → municipio → zona → comuna → barrio)

Implementado con `ST_Contains(geom, punto)` — nuevo parámetro `punto=lon,lat` en
`c98geo_map_api.php`. Al cruzar un umbral de zoom, el mapa reconstruye la cadena completa de
ancestros consultando, nivel por nivel, cuál entidad contiene el centro actual del mapa
(`map.getCenter()`), y carga el nivel objetivo ya filtrado por ese padre — funciona igual al
hacer zoom in que zoom out, sin lógica direccional especial.

| Zoom Leaflet | Nivel |
|---|---|
| ≤6 | departamento (nacional) |
| 7-8 | subregión |
| 9-10 | municipio |
| 11-12 | zona |
| 13 | comuna |
| ≥14 | barrio |

Toggle "Zoom automático" en la barra del mapa para desactivarlo si interfiere con la
navegación manual (pills / clic en polígono). Guard `programmaticZoom` evita que el propio
`fitBounds()` (llamado tras cada carga) dispare un salto de nivel en cascada.

Probado end-to-end: zoom nacional → Antioquia → Valle de Aburrá → Medellín → zona Nororiental
→ comuna Manrique → 15 barrios, y la reversa completa al hacer zoom out.
