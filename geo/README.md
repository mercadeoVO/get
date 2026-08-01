# C98_GEO — Capa geográfica maestra de Colombia

> Última actualización: 2026-07-16 (fecha de sistema de la sesión más reciente).
> Este README es el punto de entrada rápido. El detalle técnico completo vive en
> [`docs/diccionario_datos.md`](docs/diccionario_datos.md); las decisiones de arquitectura y
> el contexto del briefing original en [`CLAUDE.md`](CLAUDE.md).

## Qué es esto

Base de datos MySQL/MariaDB (`c98_geo`, `127.0.0.1:3307`) con la jerarquía geográfica completa
de Colombia — región → departamento → subregión → municipio → zona → comuna → barrio — con
geometría real (polígonos, no solo centroides) y un dashboard (`c98geo_dashboard.html` en
`medfut`) con tabla jerárquica y mapa de coropletas interactivo, sincronizados entre sí.

## Estado actual (resumen ejecutivo)

| Nivel | Filas | Con geometría real |
|---|---|---|
| Región | 5 | 5/5 |
| Departamento | 33 | 33/33 |
| Subregión | 143 | 143/143 |
| Municipio | 1.123 | 1.121/1.123 |
| Zona | 2.257 | 20/2.257 (solo zonas urbanas de las 5 ciudades grandes) |
| Comuna | 84 | 83/84 (falta Sumapaz, rural) |
| Barrio | 2.271 | 2.271 (Bogotá, Medellín, Cali, Barranquilla, Cartagena) |

Todo el detalle de cómo se llegó a estos números, qué fuentes se usaron y qué se descartó está
en el diccionario de datos. Este README no lo repite.

## Piezas del proyecto

- **`c98_geo`** (BD) — fuente de verdad geográfica. 8 tablas: `dim_region` → `dim_barrio` +
  `geo_geometry`.
- **`docs/diccionario_datos.md`** — registro técnico completo: hallazgos, correcciones,
  fuentes externas usadas, decisiones tomadas y por qué. Es el documento más largo y más
  importante del proyecto — ahí está el "cómo" y el "por qué" de todo.
- **`docs/lecciones_aprendidas.md`** — patrones de error que se repitieron en esta sesión y
  cómo evitarlos en la próxima (no repetir el mismo bug tres veces).
- **[`../MEMORIA.md`](../MEMORIA.md)** — estado y contexto del proyecto consolidado
  (`C98_MEDFUT` = `geo/` + `app/`) para retomar el trabajo en una sesión futura: qué quedó
  pendiente, qué decisiones están tomadas y no hay que volver a discutir.
- **`medfut/c98geo_dashboard.html`** + **`c98geo_api.php`** + **`c98geo_map_api.php`** —
  dashboard de Geografía. Lee exclusivamente de `c98_geo` (ver lecciones aprendidas — antes leía
  de `medfut.dim_geo` por error de integración).
- **`data_dane/`, `data_barrios/`** — descargas crudas de fuentes externas (DANE, Datos
  Abiertos Bogotá/Cartagena/Barranquilla, Alcaldía de Cali) usadas para poblar la geometría.
  Se conservan para trazabilidad y por si hace falta reprocesar.
- **`MED/`** — insumos originales de Medellín (TopoJSON de barrios, Excel, piloto Power BI) que
  ya existían antes de esta sesión y se usaron como fuente para Medellín.

## Pendientes conocidos

- **Cali**: sin fuente de barrio/comuna accesible (WFS oficial roto, ArcGIS con token). Sigue
  con solo centroide/geometría de municipio.
- **`medfut`**: tiene el mismo problema de encoding (mojibake) que se corrigió en `c98_geo` —
  no se tocó, sigue pendiente para quien mantenga esa base.
- **Antioquia**: reforma territorial en curso (9→17 regiones) — no vigente aún, no se aplicó.
- **Resolución `low`/`mid`**: solo existe para región/departamento/subregión/municipio. Zona/
  comuna/barrio solo tienen `high` (deliberado — no aportan a zoom nacional).
- **Expansión de cobertura de barrio más allá de las 5 ciudades grandes**: decisión de alcance
  no tomada, requiere definir qué municipios adicionales priorizar.

## Convenciones a respetar (de `CLAUDE.md`, siguen vigentes)

- DIVIPOLA como llave universal, siempre `CHAR`/`VARCHAR`, nunca `INT` (por el cero a la
  izquierda — ver lecciones aprendidas, este bug se repitió varias veces).
- SRID explícito siempre (`EPSG:4326`).
- Ninguna sentencia destructiva sin backup previo — y backups se limpian una vez confirmado que
  no hacen falta (no dejarlos acumular indefinidamente).
