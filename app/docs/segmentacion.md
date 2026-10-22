# Segmentación de leads — contrato y notas

`leads_segmentacion.html` (dashboard) + 3 acciones nuevas en `leads_importer.php`:
`segment_dims`, `segmentar`, `segmento_export`. Helper compartido `buildSegmentWhere()`.

## Objetivo

Un solo tablero donde cada dimensión de análisis (demografía, política, geografía,
temas/percepción, afinidad de marca por pregunta) es un filtro combinable con AND. Devuelve
tamaño del segmento, distribuciones agregadas, un mapa (coropleta de densidad **o** contorno)
y exportación del segmento a CSV con contactos.

## UX del dashboard (2026-09)

- **Layout**: bloque de filtros a 100% de ancho arriba (tarjetas en grid horizontal, cada una
  con alto acotado y scroll interno) + bloque inferior con **Mapa 50% / Distribuciones 50%**.
- **Consulta reactiva**: no hay botón "ejecutar". Cada cambio de filtro dispara `runQuery()`
  con debounce de ~320 ms y descarta respuestas obsoletas por número de secuencia. Mientras
  calcula se muestra un cronómetro (`#timer`) con el tiempo transcurrido.
- **Filtros como tiles cuadradas** en grid `auto-fill` para demografía, política, plataforma
  y subtemas.
- **Geografía**:
  - Fila de **botones de nivel**: Región · Depto. · Mpio. · Zona · Comuna · Barrio. Región y
    Departamento siempre disponibles; Municipio/Zona/Comuna/Barrio se habilitan solo tras
    entrar (drill) a un área padre — la geometría nacional de esos niveles pesa demasiado
    (municipio 8.8 MB, barrio 20 MB), por eso el mapa siempre pide geometría acotada por
    `padre`.
  - **Tiles = solo áreas con registros** en el segmento actual (de `geo_map`), ordenadas por
    conteo desc. Cambian con cada consulta.
  - El `›` de una tile (o un click en el polígono del mapa) profundiza al siguiente nivel;
    breadcrumb `Colombia › Caribe › …`. El filtro usa la selección más profunda; si no hay
    selección al nivel actual pero sí drill, filtra por el último ancestro.
- **Temas**: acordeón `tema → subtemas` (`dim_tema`), cada subtema es una tile toggle.
- **Mapa**: fondo gris (tiles OSM con `filter: grayscale(1)…`), coropleta **verde → rojo**
  (`#1a9850 … #d73027`, 5 buckets) por densidad de leads del segmento; solo se pintan las
  áreas con registros. Toggle Densidad ⇄ Contorno.
- **Tema claro/oscuro** con toggle en el header, persistido en `localStorage` (`seg_theme`),
  transiciones suaves en color/borde/sombra.

## Objeto de filtros (contrato JSON)

Se envía como `POST` param `filtros` (JSON string) a `segmentar` y `segmento_export`.

```jsonc
{
  "sexo":               ["Mujer"],            // IN — valores exactos de import_log_leads.sexo
  "rango_edad":         ["35a44"],            // IN
  "estrato":            [3,4],                // IN
  "nivel_educativo":    ["universitaria"],    // IN
  "platform":           ["fb","ig"],          // IN
  "afiliacion_politica":["derecha"],          // IN — valores exactos (texto ya normalizado en BD)
  "temas":              [25,21],              // IN dim_tema.id — respondió algo clasificado en ese tema/subtema
  "is_organic":         0,                    // 0 | 1
  "created_desde":      "2025-01-01",         // >= 00:00
  "created_hasta":      "2025-03-31",         // <  +1 día (inclusivo)
  "import_ids":         [12,15],              // IN import_log.id
  "geo": { "nivel": "region", "ids": [4] },   // ver niveles abajo
  "preguntas": [                              // condiciones EAV — una EXISTS por entrada
    { "pregunta_id": 397, "op": "in",       "valores": ["Cercana","De confianza"] },
    { "pregunta_id": 392, "op": "answered", "valores": [] },
    { "pregunta_id": 180, "op": "not_in",  "valores": ["Muy desfavorable"] }
  ],
  "nivel_mapa": "departamento"                // nivel de agregación de geo_map
}
```

`temas` → `AND EXISTS (SELECT 1 FROM import_log_leads_respuestas r JOIN pregunta_tema pt
ON pt.pregunta_id = r.pregunta_id WHERE r.lead_id = l.id_lead AND pt.tema_id IN (...))`.
Cada `dim_tema.id` es una combinación (tema, subtema) concreta.

Campos ausentes / arrays vacíos → sin filtro. Todo va parametrizado (`?`); columnas y
niveles se validan contra whitelist (`SEGMENT_COLS`, `SEGMENT_GEO_NIVELES`) — nunca se
interpola texto del request en el SQL.

### Niveles geográficos (`geo.nivel` y `nivel_mapa`)

| nivel | `geo.ids` contiene | predicado sobre `import_log_leads.id_geo` (DANE VARCHAR(15)) |
|---|---|---|
| `region`       | `dim_region.id` (INT) | `LEFT(id_geo,2) IN (SELECT id FROM c98_geo.dim_departamento WHERE id_region IN (...))` |
| `departamento` | `dim_departamento.id` (VARCHAR 2) | `LEFT(id_geo,2) IN (...)` |
| `subregion`    | `dim_subregion.id` (VARCHAR 5) | `LEFT(id_geo,5) IN (SELECT id FROM c98_geo.dim_municipio WHERE id_subregion IN (...))` |
| `municipio`    | `dim_municipio.id` (VARCHAR 5) | `LEFT(id_geo,5) IN (...)` |
| `zona`         | `dim_zona.id` (VARCHAR 8) | `CHAR_LENGTH(id_geo)>=8 AND LEFT(id_geo,8) IN (...)` |
| `comuna`       | `dim_comuna.id` (VARCHAR 11) | `CHAR_LENGTH(id_geo)>=11 AND LEFT(id_geo,11) IN (...)` |
| `barrio`       | `dim_barrio.id` (VARCHAR 15) | `id_geo IN (...)` |

`op` de EAV: `in` (respondió alguno de los valores), `not_in` (`NOT EXISTS ... IN`),
`answered` (respondió cualquier cosa a esa pregunta).

## Respuestas

### `GET segment_dims`
`{ ok, sexo:[{val,cnt}], rango_edad, estrato, platform, afiliacion_politica, nivel_educativo,
   total_leads, imports:[{id,archivo,fecha,leads}], regiones:[{id,nombre}],
   departamentos:[{id,nombre,id_region}],
   preguntas:[{id,pregunta,dominio,tema,subtema,codigo,es_electoral,n}],
   temas:[{id,dominio,tema,subtema,codigo,n}] }   // n = leads distintos que respondieron algo del tema

Los valores de respuesta por pregunta se traen aparte con `respuestas_muestra?pregunta_id=`.

### `POST segmentar`
`{ ok, total, total_global, pct_del_total, dist:{sexo,rango_edad,estrato,afiliacion_politica,
   nivel_educativo,platform}, timeline:[{fecha:'YYYY-MM',nuevos}], nivel_mapa,
   geo_map:[{geo_id,nombre,cnt}], sin_geo, mapeados }`

`geo_map` se cruza en el front con las geometrías de `c98geo_map_api.php?tipo=<nivel_mapa>`.

### `POST segmento_export`
CSV (`text/csv`, con BOM). Columnas: `lead_id, nombre, email, telefono, sexo, rango_edad,
estrato, nivel_educativo, afiliacion_politica, plataforma, id_geo, departamento, municipio,
created_time, campaign_name`. Sin `LIMIT`.

## Limitaciones conocidas (por la naturaleza de los datos)

1. **Afinidad de marca / gustos / opinión = respuestas EAV.** No son columnas del lead;
   viven en `import_log_leads_respuestas` (join `r.lead_id = l.id_lead`). Una condición de
   encuesta solo matchea leads que **contestaron esa encuesta concreta**. Los leads de la
   encuesta de Alpina y los de la encuesta de tendencia política suelen ser lotes distintos:
   cruzarlos puede dar 0 aunque cada filtro por separado tenga miles.
2. **`geo_map` no suma al total.** Los leads con `id_geo` NULL (`sin_geo`) o con un prefijo
   más corto que el nivel del mapa (ej. `id_geo` de 2 chars cuando el mapa es municipio) no
   aparecen en `geo_map`. `mapeados` = suma de `geo_map`; `total - mapeados - sin_geo` =
   leads con ubicación pero de menor granularidad que el nivel del mapa.
3. **Cobertura de geometría:** `zona`/`comuna`/`barrio` solo existen para Bogotá, Medellín,
   Cali, Barranquilla y Cartagena (`c98_geo.geo_geometry`). El mapa muestra "sin geometría"
   para el resto. Municipio: `mid`/`high` (no `low`). Región/departamento/subregión: `low`.
4. **`afiliacion_politica` viene de varios headers** ("tendencia política", "simpatía
   política", "partido político", "afiliación política") normalizados a un solo valor de
   texto. El dashboard ofrece los valores distintos reales (`derecha`, `centro_derecha`,
   `izquierda`, `sin_afiliación`, …) como multiselección exacta.
5. **Pregunta 397** ("¿con cuáles atributos asocias a Alpina?") tiene `es_electoral=1` por
   falso positivo del keyword-match ("selecci**ón**" → "elecci"). El modal de condiciones
   la oculta salvo que se marque "mostrar preguntas electorales".
