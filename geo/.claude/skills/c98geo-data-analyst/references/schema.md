# Esquema real de `c98_geo`

MariaDB 10.4.32, host `127.0.0.1:3307`. Sin `.env` en el repo — credenciales viven fuera del
árbol del proyecto (ver quien las tenga configuradas localmente; no asumas usuario/clave).

## Jerarquía dimensional (7 tablas, FK real en cada eslabón)

| Tabla | Filas (última verificación) | PK | FK al padre |
|---|---|---|---|
| `dim_region` | 5 | `id` INT AUTO_INCREMENT | — |
| `dim_departamento` | 33 | `id` VARCHAR(2) | → `dim_region.id` |
| `dim_subregion` | 143 | `id` VARCHAR(5) | → `dim_departamento.id` |
| `dim_municipio` | 1.123 | `id` VARCHAR(5) | → `dim_departamento.id`, → `dim_subregion.id` (nullable) |
| `dim_zona` | 2.257 | `id` VARCHAR(8) | → `dim_municipio.id` |
| `dim_comuna` | 84 | `id` VARCHAR(11) | → `dim_zona.id` |
| `dim_barrio` | 2.271 | `id` VARCHAR(15) | → `dim_comuna.id` |

Cada nivel tiene `UNIQUE(padre_id, nombre)` — no es adjacency-list de una sola tabla, son 7
tablas encadenadas. Consultas jerárquicas requieren JOIN en cadena, `WITH RECURSIVE` no aplica
directo (no hay una sola tabla self-referencing).

`dim_comuna.tipo` distingue `'comuna'` vs `'localidad'` (Bogotá usa localidad).

## `geo_geometry` — geometría, separada de las tablas de entidad

```
id | tipo | id_tipo | resolution | geom | topojson | centroid_lat | centroid_lon | source | updated_at
```

- `tipo` ∈ {región, departamento, subregión, municipio, zona, comuna, barrio}.
- `id_tipo` → FK polimórfica hacia `dim_<tipo>.id` — **sin constraint de motor**, MySQL/MariaDB
  no puede declarar FK condicional por valor de otra columna. Integridad depende de disciplina
  de aplicación. 0 huérfanos verificados en la última auditoría, pero no hay garantía automática.
- `geom` — `MULTIPOLYGON`, `SRID 4326` (WGS84, equivalente sub-métrico a MAGNA-SIRGAS/EPSG:4686
  oficial de Colombia — irrelevante salvo que mezcles capas sin declarar SRID).
- `resolution` — `ENUM('low','mid','high')`. `low` ≈ Douglas-Peucker tol. 0.01°, `mid` ≈ 0.001°,
  `high` = geometría completa sin simplificar. No todos los niveles tienen las 3 resoluciones
  (municipio no tiene `low`, zona/comuna/barrio solo tienen `high` — no aportan en zoom lejano).
- `centroid_lat`/`centroid_lon` — recalculados desde el polígono vía `ST_Centroid(geom)`, no son
  una lista externa de coordenadas.
- Total actual: 5.341 filas (departamento 33, municipio 1.121, barrio 2.271, + derivados de
  región/subregión/zona/comuna por `ST_Union`, × hasta 3 resoluciones donde aplica).

## Tablas de backup acumuladas (pendiente de limpieza — ver P6 en docs/procesos.md)

Durante la carga se generaron múltiples snapshots antes de operaciones destructivas:
`dim_region_bkp`, `dim_departamento_bkp`, `dim_municipio_bkp`, `dim_comuna_bkp`,
`dim_barrio_bkp`, `dim_barrio_bkp3`, `dim_zona_bkp`, `geo_geometry_bkp` … `geo_geometry_bkp7`.
No son basura accidental — son puntos de rollback deliberados — pero no se ha decidido cuáles
conservar a largo plazo.
