# Importador de leads — validación de estructura por hoja

Solo se importan hojas que son **exportaciones de leads de Meta Ads** (Lead Ads → «Descargar
leads»). Los resúmenes, tablas dinámicas, catálogos de ubicación (`UBI`) o hojas auxiliares que
suelen venir en el mismo Excel se muestran como **no válidas**, con el motivo, y no se pueden
seleccionar ni importar.

## La regla

Una exportación de Meta trae en la fila 1, en este orden:

```
id, created_time, ad_id, ad_name, adset_id, adset_name, campaign_id, campaign_name,
form_id, form_name, is_organic, platform, <preguntas del formulario…>, [lead_status]
```

Se toma como encabezado la **primera fila con datos** de la hoja y se compara cada columna
normalizada (minúsculas, sin tildes, `_`/`-` → espacio: «Created Time», «created_time» y
«created time» valen igual).

1. **Obligatorias (las 9):** `created_time, ad_id, ad_name, adset_id, adset_name, campaign_id,
   campaign_name, form_id, form_name`. Si falta alguna, la hoja no es válida
   («No tiene las columnas fijas de Meta Ads: faltan …»).
2. **ID del lead:** debe haber una columna `id` (o `lead id`). Si no la hay:
   - si la columna 1 es otra columna fija de Meta (p. ej. `created_time`), la hoja **no es
     válida**: el importador usaría la fecha como ID y fusionaría leads distintos;
   - si la columna 1 tiene otro título o está vacía (cargas antiguas donde se renombró o borró el
     título de `id`), la hoja **es válida con aviso**: se usa la columna 1 como ID del lead
     (comportamiento que el importador ya tenía).
3. `is_organic` y `platform` son **opcionales** (si están, se importan).

## Por qué este mínimo

Revisión de los ~1.200 encabezados de hojas de los Excel subidos (`app/tmp/`, nov-2026):

| Encabezado | Hojas | Resultado |
|---|---|---|
| Las 12 columnas fijas, `id` en la columna 1 | 818 | válida |
| Las 12, con una columna extra antes (`page, id, …`) | 23 | válida |
| 11 de 12: `id` con otro título o vacío en la columna 1 | 21 | válida con aviso |
| 11 de 12: empieza en `created_time`, sin `id` | 2 | **no válida** |
| 0–4 columnas fijas (resúmenes, `UBI`, `cuestionario`, `PAUTA-FICO`, tablas dinámicas…) | ~325 | **no válida** |

Ninguna exportación real omite una de las 9 obligatorias, y todo lo que no las tiene es un
resumen o una hoja auxiliar. Por eso no se exige que estén en posiciones fijas (hay exportaciones
con una columna antes de `id`), sino que estén todas en el encabezado.

## Dónde se aplica

- **`ExcelReaderPhp.php`** — `validarHojaMeta(array $encabezado)` (la regla) y
  `anotarValidezHojas($sheets)`. `getSheets()` devuelve ahora también `headers` (primera fila con
  datos) y `readHeader($hoja)` lee solo el encabezado. `cols` sale del encabezado cuando el xlsx no
  trae `<dimension>`; esa era la causa de que todas las tarjetas mostraran «0 col».
- **`leads_importer.php?action=get_sheets`** y **`drive_scan.php?action=traer`** — cada hoja llega
  con `valid`, `reason`, `missing` y `warning`. Si **ninguna** hoja es válida, se rechaza el archivo
  completo (HTTP 422) y se borra la subida recién hecha.
- **`leads_importer.php?action=import`** — antes de escribir nada, se valida el encabezado de
  todas las hojas pedidas. Basta con que una no sea válida para rechazar la petición (HTTP 422,
  «Hojas no válidas, no se importó nada…»).
- **`leads_import.html`** (paso «Seleccionar hojas») — las hojas no válidas se ven deshabilitadas
  (borde punteado, checkbox desactivado) con el motivo, y no se pueden seleccionar.
- La web en Vercel (`api/leads_importer.js`) no importa Excel (solo local), así que no aplica.

La lista de 12 columnas de `drive_lib.php` (`driveColumnasRequeridas`) sigue sirviendo solo para
clasificar el inventario de Drive («completo/parcial»); la que decide si se importa es esta.
