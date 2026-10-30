# C98_GEO — División del proyecto en procesos

> Generado 2026-07-25. Reorganiza el "Plan de fases" del `CLAUDE.md` original en procesos
> discretos con estado real (verificado contra `docs/diccionario_datos.md`), para poder
> priorizar y asignar trabajo sin releer todo el histórico. Cada proceso indica qué skill de
> Claude Code conviene invocar si se retoma.

Leyenda de estado: ✅ Cerrado · 🟡 Parcial / requiere decisión · ⬜ No iniciado · 👁️ Vigilancia (sin acción)

---

## P1 — Reconocimiento del esquema real
**Estado:** ✅ Cerrado
**Entregable:** `docs/diccionario_datos.md`
**Resumen:** inventario de las 7 tablas `dim_*` + `geo_geometry`, jerarquía real por FK en
cadena (no adjacency-list), reconciliación de las 1.326 filas originales de `geo_geometry`.
**Skill sugerida:** `data:explore-data` (para futuras extensiones de esquema).

## P2 — Diagnóstico de integridad estructural (H-01, H-04 parcial, H-05, H-06)
**Estado:** ✅ Cerrado
**Resumen:** duplicados DIVIPOLA imposibles por PK, valores centinela Excel en 0 filas,
región única y canónica (5 regiones, 33/33 deptos), invariante `LEFT(id_mpio,2)=id_dpto` sin
violaciones. `geo_geometry` sigue sin FK real de motor (ver P6).
**Skill sugerida:** `data:validate-data`.

## P3 — Validación geoespacial (bounding box, encoding)
**Estado:** ✅ Cerrado
**Resumen:** 0 outliers de bounding box Colombia en todos los niveles. Hallazgo no anticipado
H-09 (mojibake UTF-8↔CP850 en `nombre`, 491 filas) detectado y corregido con backup previo.
**Skill sugerida:** `data:validate-data`, `data:statistical-analysis`.

## P4 — Decisión de arquitectura (Opción A vs B del briefing)
**Estado:** ✅ Cerrado (decisión ya tomada, distinta de las dos opciones originales)
**Resumen:** no se migró a adjacency-list ni se blindó el polimorfismo vía triggers. El estado
real encontrado ya era un tercer modelo: 7 tablas dimensionales encadenadas por FK real al
padre inmediato. `geo_geometry` es la única pieza que sigue polimórfica — ver P6.
**Skill sugerida:** `engineering:architecture` (si se quiere dejar el ADR por escrito).

## P5 — Poblar geometría real (DANE + fuentes municipales)
**Estado:** ✅ Cerrado para las 5 ciudades >1M hab. · 🟡 Parcial a nivel nacional de barrio/comuna
**Resumen:** departamento 33/33, municipio 1.121/1.123 (fuente DANE MGN2025), barrio 2.271
(Bogotá vía Sector Catastral, Medellín, Cali, Barranquilla, Cartagena), región/subregión/zona/
comuna derivados por `ST_Union`. Resolución `low`/`mid`/`high` generada. Dos municipios sin
geometría por discrepancia de catálogo (`27086`→`27493` migrado; `94663` Mapiripán pendiente
de investigar causa).
**Pendiente:** `94663` Mapiripán sin geometría, decisión de expandir barrio/comuna fuera de las
5 ciudades grandes (bloqueado por disponibilidad de fuente, no por capacidad técnica).
**Skill sugerida:** `data:sql-queries`, `engineering:documentation`.

## P6 — Enforcement continuo de integridad
**Estado:** ⬜ No iniciado
**Pendiente:**
- FK real / constraint en `geo_geometry` (hoy `tipo`/`id_tipo` sigue sin FK de motor).
- `SPATIAL INDEX(geom)` en las tablas con geometría.
- Vista `v_integridad_geo` que exponga huérfanos/huecos en tiempo real.
- Test CI que falle si aparece un huérfano.
- Limpieza de las ~7 generaciones de tablas `*_bkp*` acumuladas durante la carga (`dim_*_bkp`,
  `geo_geometry_bkp` … `geo_geometry_bkp7`) — decidir cuáles conservar como snapshot final y
  cuáles dropear.
**Bloquea:** P7 (demografía), por diseño del plan original.
**Skill sugerida:** `engineering:architecture` (ADR de la vista + constraint), `engineering:testing-strategy` (test CI), `engineering:tech-debt` (limpieza de backups).

## P7 — Demografía y población digital
**Estado:** ⬜ No iniciado (bloqueado hasta cerrar P6)
**Alcance:** cruzar `c98_geo` con `DEMO_CO_2025_GENAGE`, `censoelectoral_2025`, `TEMAS_ANA_CO` /
`TEMAS_MPIO_SEC` (audiencias digitales tipo Meta Ads) y fuentes externas de población digital
(ONTIC/MinTIC, datos.gov.co).
**Skill sugerida:** `data:analyze`, `data:sql-queries`, `data:build-dashboard` o `data:create-viz` para la salida.

## P8 — Sincronización dashboard ↔ BD real
**Estado:** ✅ Cerrado
**Resumen:** `c98geo_api.php` y `c98geo_map_api.php` reescritos para leer 100% de `c98_geo` (se
detectó que antes leían por accidente `C98_GET.dim_geo`, tabla de otro proceso). Sync tabla↔mapa
migrado de matching por nombre a `id` exacto. Zoom automático por nivel (`ST_Contains`)
implementado y probado end-to-end.
**Skill sugerida:** ninguna pendiente — mantener con `engineering:documentation` si crece la API.

## P9 — Expansión de cobertura sub-municipal fuera de las 5 ciudades grandes
**Estado:** 🟡 Decisión pendiente del usuario
**Contexto:** OSM Overpass se descartó por cobertura insuficiente (Antioquia completa solo tiene
1 relación `admin_level=10` en todo OSM). Requiere fuente municipio por municipio (catastro
local) — esfuerzo alto, sin fuente nacional única conocida.
**Skill sugerida:** `data:explore-data` para evaluar fuentes candidatas antes de comprometer esfuerzo.

## P10 — Seguimiento reforma territorial Antioquia (9→17 subregiones)
**Estado:** 👁️ Vigilancia, sin acción
**Resumen:** proyecto de ordenanza departamental en curso al momento del análisis, sin vigencia
legal plena confirmada. No tocar `dim_subregion` de Antioquia hasta que quede en firme.
**Skill sugerida:** ninguna — revisar manualmente cuando haya novedad.

## P11 — Auditoría del piloto Power BI
**Estado:** ⬜ No auditado
**Contexto:** `MED/piloto.pbix` (7 MB) existe en el árbol del proyecto pero no fue parte del
análisis de esta sesión ni de las anteriores documentadas en `diccionario_datos.md`. Desconocido
si consume `c98_geo` directo, `C98_GET`, o un extracto estático (`geo_med.xlsx`,
`medellin_barrios_topo_v2.json`).
**Skill sugerida:** `anthropic-skills:powerbi-design` si se retoma o rediseña.

---

## Mapa rápido proceso → skill

| Proceso | Skill(s) |
|---|---|
| P1, P5, P9 | `data:explore-data`, `data:sql-queries` |
| P2, P3 | `data:validate-data` |
| P4, P6 | `engineering:architecture` |
| P6 | `engineering:testing-strategy`, `engineering:tech-debt` |
| P7 | `data:analyze`, `data:build-dashboard` / `data:create-viz` |
| P11 | `anthropic-skills:powerbi-design` |
| Transversal | `data:data-context-extractor` — skill persistente de esquema/gotchas del proyecto (ver abajo) |

## Skill persistente instalada para este proyecto

Se generó una skill de proyecto (`data-context-extractor`, modo bootstrap) que encapsula el
esquema de `c98_geo`/`C98_GET`, la jerarquía DIVIPOLA, y los gotchas descubiertos durante la
carga (mojibake, padding de códigos, `ST_Union` en vez de agregación nativa, workspace WFS
correcto de Cali, etc.), para que una sesión nueva de Claude Code no tenga que re-descubrirlos
leyendo `diccionario_datos.md` completo cada vez.
