# Cross-DB: MEDFUT (BD `C98_MEDFUT`) ↔ c98_geo

> Renombrada 2026-07-30: la BD del proyecto MEDFUT se llamaba `medfut`, ahora es `C98_MEDFUT`
> (el proyecto/carpeta/URL siguen llamándose "medfut" — solo cambió el nombre del schema SQL).

`C98_MEDFUT` y `c98_geo` viven en la misma instancia MariaDB (127.0.0.1:3307) — se cruzan con
prefijo `c98_geo.tabla` directo en SQL, sin necesidad de federación.

`C98_MEDFUT.import_log_leads.id_geo` es `VARCHAR(15) NULL` con el código DANE jerárquico de
`c98_geo` (reemplazó una FK `INT` a `dim_geo`, tabla que sigue existiendo físicamente pero está
deprecada — ver gotcha #7). Es la **única columna geo que persiste** en `import_log_leads` desde
el 2026-07-28: `region`, `geo_departamento`, `subregion`, `municipio`, `zona`,
`comuna_localidad` y `barrio` se ELIMINARON de la tabla (existían como texto libre además de
`id_geo`, se decidió normalizar). Backup del histórico previo en
`C98_MEDFUT.import_log_leads_geo_bkp`.

## Patrón de resolución por longitud (para leer id_geo ya guardado)

```sql
LEFT JOIN c98_geo.dim_departamento dpto ON dpto.id = LEFT(l.id_geo, 2)   AND l.id_geo IS NOT NULL
LEFT JOIN c98_geo.dim_municipio    m    ON m.id    = LEFT(l.id_geo, 5)   AND l.id_geo IS NOT NULL
LEFT JOIN c98_geo.dim_zona         z    ON z.id    = LEFT(l.id_geo, 8)   AND CHAR_LENGTH(l.id_geo) >= 8
LEFT JOIN c98_geo.dim_comuna       c    ON c.id    = LEFT(l.id_geo, 11)  AND CHAR_LENGTH(l.id_geo) >= 11
LEFT JOIN c98_geo.dim_barrio       b    ON b.id    = l.id_geo            AND CHAR_LENGTH(l.id_geo) = 15
```

Nota: `id_geo` puede tener cualquier longitud válida (2/5/8/11/15) según cuál fue el nivel más
granular resuelto para ese lead. El caso de **2 caracteres (solo departamento)** es real y
frecuente — campañas de Meta Ads con targeting regional amplio sin ciudad específica (tag
`"(SC)"` = sin ciudad en `adset_name`) solo resuelven hasta departamento. El `dpto` de arriba se
joinea directo por `LEFT(id_geo,2)`, no a través de `m.id_departamento` — así cuenta también
los leads que solo llegaron a nivel departamento. El guard `CHAR_LENGTH(l.id_geo) >= N` evita
joins falsos-positivos por `LEFT()` truncando un código más corto.

## Cómo se calcula `id_geo` al importar (`leads_importer.php`)

Ya no hay una sola función monolítica — se dividió en 3, todas operan sobre un array `$lead` en
memoria (nunca se persiste el texto crudo intermedio):

1. **`reconcileLeadGeo($lead, $db)`** — siempre corre. Deriva niveles faltantes a partir de
   campos YA presentes en `$lead` (ej. si vino "barrio" mapeado directo de una columna del
   Excel pero no "municipio", lo deriva). No adivina desde `adset_name`.
2. **`autoParseLeadGeo($lead, $db, $levels, $source)`** — **opt-in, apagado por defecto.** Solo
   corre si el usuario activó el toggle "🧭 Extracción automática de ubicación" en el wizard de
   `leads_import.html`, eligiendo columna origen (`adset_name`/`campaign_name`/`ad_name`/
   `form_name`) y niveles. Internamente llama a `parseAdsetForGeo()`.
3. **`resolveGeoId($lead, $db)`** — con lo que haya quedado en `$lead` (de mapeo directo y/o del
   parseo opt-in), resuelve el `id` más granular de `c98_geo`: P1=barrio, P2=comuna, P2b=zona,
   P3=municipio, **P4=departamento solo** (fallback agregado 2026-07-28, para el caso "(SC)").
   Devuelve `?string` o `null`. Recordar el gotcha de padding (#5) — un código sin `LPAD`
   correcto falla en silencio, no lanza error.

El resultado de `resolveGeoId()` es lo único que se guarda (`id_geo`); `$lead['municipio']`,
`$lead['geo_departamento']`, etc. son transitorios y se descartan después del INSERT.
