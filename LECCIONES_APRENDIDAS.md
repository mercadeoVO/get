# C98_MEDFUT — Lecciones aprendidas (lado app/GET)

> Patrones de error del trabajo sobre `app/` (GET, antes MEDFUT) durante la sesión del 2026-07-27/28. Para
> las lecciones específicas de la carga geoespacial de `geo/` (C98_GEO), ver
> [`geo/docs/lecciones_aprendidas.md`](geo/docs/lecciones_aprendidas.md) — no se repiten aquí.

## 1. Un `ALTER TABLE` documentado en `leads_schema.sql` no significa que la BD real lo tenga

**Qué pasó:** `leads_schema.sql` declaraba las columnas `subregion` y `barrio` en
`import_log_leads`, y `leads_importer.php` las usaba en el `INSERT`. Pero la tabla real en la
BD nunca las tuvo — el `ALTER TABLE` que las agregaría simplemente nunca se ejecutó contra
producción. Resultado: `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'subregion'`
al importar, después de meses de que otras columnas sí funcionaran bien.

**Por qué costó detectarlo:** el error solo aparece al ejecutar el `INSERT`, no al leer el
schema.sql ni al abrir el wizard — nada en la UI advierte que el archivo de definición y la BD
real divergieron.

**Regla a aplicar:** cuando el schema.sql y el comportamiento real de la BD parezcan
inconsistentes, verificar con `DESCRIBE tabla` contra la BD real antes de asumir que el
`.sql` del repo es la fuente de verdad — puede estar adelantado (declara algo que nunca se
aplicó) o atrasado (no refleja un cambio manual hecho directo en producción).

## 2. Una columna duplicada en el Excel de origen se resuelve por *substring*, no por igualdad — y la última gana en silencio

**Qué pasó:** Meta exporta `adset_name` dos veces en el mismo archivo (una rica en contenido,
otra truncada). El lector renombra la segunda a `adset_name_2` para evitar colisión de headers.
`detectColType()` usa `str_contains($normalizado, $patron)` para reconocer columnas — y
`"adset name 2"` contiene como substring literal a `"adset name"`. Las dos columnas terminaban
detectadas como el mismo campo, y la que aparecía más a la derecha en la hoja pisaba en
silencio el valor de la primera al llenar el lead (sin condición de "no sobreescribir" para
columnas de tipo fijo, solo existía esa protección para las reglas de extracción manuales).

**Por qué costó detectarlo:** el import no fallaba, no había error, y el campo se veía
poblado (con el valor equivocado) — parecía un problema de las reglas de extracción del
usuario, no de qué columna llegaba realmente al pipeline.

**Regla a aplicar:** cuando dos columnas del origen puedan matchear el mismo tipo detectado
(headers duplicados, variantes con sufijo numérico del lector), la primera columna no vacía
debe ganar, no la última — es la única forma de que el comportamiento sea predecible sin
depender del orden exacto de las columnas en el archivo.

## 3. Una cascada de 0% en varios niveles relacionados casi siempre tiene una sola causa raíz común, no N causas independientes

**Qué pasó:** Departamento, Municipio, Subregión, Zona, Comuna y Barrio cayeron todos a 0% de
cobertura a la vez para una importación. La tentación inicial fue tratarlo como "la regla de
extracción de Departamento falló" + "la regla de Municipio falló" (dos problemas). La causa
real era una sola: `id_geo` nunca se resolvía (el pipeline no tenía ningún camino para resolver
"solo departamento, sin ciudad conocida" — el caso real de campañas con targeting regional
amplio, tag `"(SC)"` = sin ciudad), y como el dashboard mide cobertura vía `JOIN` a través de
`id_geo` (no por presencia de texto crudo), toda la cascada de niveles caía junta.

**Regla a aplicar:** cuando varios campos relacionados jerárquicamente fallan juntos y al
mismo tiempo, buscar primero el punto único de donde todos dependen (aquí: la resolución de
`id_geo`) antes de diagnosticar cada campo por separado.

## 4. Cachear el DOM en herramientas de automatización de navegador puede mostrar una versión vieja de la página sin avisar

**Qué pasó:** después de editar `leads_analytics.html` para quitar "Geo (FK)" de la lista de
cobertura, `get_page_text` seguía devolviendo el texto viejo incluso tras navegar de nuevo a la
URL. La verificación real (`document.querySelector('#cob-list').innerHTML.includes(...)`
ejecutada directo en la página) mostró que el cambio sí estaba aplicado — la herramienta de
lectura de texto tenía una instantánea vieja cacheada, no la página.

**Regla a aplicar:** ante una discrepancia entre "el código dice que cambié esto" y "la
herramienta de verificación dice que sigue igual", verificar con una fuente más directa
(ejecutar JS en la página real, o inspeccionar la respuesta HTTP cruda) antes de asumir que la
edición no funcionó.

## 5. Un cambio de esquema pedido por el usuario que revierte trabajo reciente propio merece confirmación explícita, no ejecución directa

**Qué pasó:** tras construir todo el pipeline de extracción geográfica multinivel (con
`region` incluida, a pedido explícito del usuario unos mensajes antes), el usuario pidió
eliminar exactamente esas columnas de la BD. Antes de ejecutar el `DROP COLUMN`
(irreversible sobre ~78.000 filas, y que rompía en cascada el código recién escrito si no se
actualizaba en el mismo cambio), se preguntó explícitamente el alcance con `AskUserQuestion`
en vez de asumir.

**Regla a aplicar:** una instrucción destructiva y de alto impacto que además revierte
trabajo reciente del mismo hilo de conversación es una señal fuerte para confirmar alcance
antes de ejecutar — no por desconfiar de la instrucción, sino porque el costo de una
confirmación rápida es mucho menor que el de un `DROP COLUMN` mal alcanzado sobre datos de
producción.

## 6. Mover una carpeta que es el propio directorio de trabajo del agente falla con "resource busy" — usar copia archivo-por-archivo, no `mv`/`Move-Item` sobre la carpeta raíz

**Qué pasó:** al consolidar `C98_GEO` dentro de `C98_MEDFUT`, `mv` y `Move-Item` fallaron con
"Device or resource busy" / "en uso" — la carpeta origen era el `cwd` de esta misma sesión de
Claude Code, y Windows bloquea el rename/delete de un directorio mientras algún proceso lo
tiene como directorio actual (los archivos individuales dentro sí se pueden mover).

**Cómo se resolvió:** `robocopy origen destino /E /MOVE` copia y borra archivo por archivo —
solo falla al final al intentar borrar la carpeta raíz vacía (que sigue bloqueada), dejando un
cascarón vacío inofensivo en el origen.

**Regla a aplicar:** si un `mv`/`Move-Item` de una carpeta completa falla por "recurso
ocupado" y la carpeta en cuestión podría ser el directorio de trabajo de la sesión actual (o de
otro proceso vivo), no reintentar el mismo comando — usar `robocopy /MOVE` (o equivalente
archivo-por-archivo) y aceptar que la carpeta raíz vacía quede como remanente a limpiar después
de cerrar la sesión que la bloquea.
