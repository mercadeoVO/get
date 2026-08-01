# Lecciones aprendidas — C98_GEO

> Patrones de error que se repitieron durante el trabajo sobre este proyecto, y cómo se
> detectaron/corrigieron. El objetivo de este documento es no volver a perder tiempo en el
> mismo tipo de bug. Si algo de esta lista vuelve a pasar, es una señal de que hay que
> automatizar la verificación correspondiente (test, check de import, etc.) en vez de seguir
> confiando en revisión manual.

## 1. El cero a la izquierda en códigos DIVIPOLA rompe joins silenciosamente — 3 veces

**Qué pasó:** al cargar barrios de Medellín, luego Cali, luego las localidades de Bogotá, el
mismo bug apareció tres veces: la fuente externa traía el código de comuna/localidad
zero-padded (`'01'`, `'05'`), pero `dim_comuna.codigo_local` en la BD lo tiene sin padding
(`'1'`, `'5'`). El lookup `comuna_por_codigo.get('01')` devuelve `None` en vez de lanzar un
error — así que el `if not comuna_id: continue` se ejecuta en silencio, sin ningún mensaje,
y esas filas simplemente desaparecen del resultado sin que nada lo señale.

**Por qué es peligroso:** no lanza excepción. La única señal es un conteo final más bajo de lo
esperado, que solo se nota si alguien compara contra un número de referencia. En el caso de
Bogotá, esto dejó 9 de 20 localidades en cero barrios durante un rato antes de que se detectara.

**Cómo se corrigió cada vez:** una función `norm_codigo()` que hace `str(int(c))` para quitar
el padding antes de cualquier lookup contra `codigo_local`.

**Regla a aplicar de ahora en adelante:** cualquier join contra `codigo_local` (o cualquier
columna que almacene un código DIVIPOLA/administrativo como texto) debe normalizar AMBOS lados
de la comparación antes de comparar, nunca asumir que el padding coincide. Mejor todavía: al
diseñar una tabla nueva, decidir una convención única (con o sin padding) y no depender de
inferencia caso por caso.

## 2. Los falsos positivos de encoding por consola de Windows generaron varias alarmas innecesarias

**Qué pasó:** al imprimir nombres con tildes/ñ desde Python en Git Bash sobre Windows, la
consola muestra `�` o `Usaqu�n` para caracteres que en la base de datos están perfectamente
codificados en UTF-8. Esto pasó al menos 3 veces en la sesión (Belén de Bajirá, "Bogota"/"Cali"
en `medfut`, localidades de Bogotá) y cada vez generó una sospecha de corrupción real que
requirió verificación adicional.

**Cómo se distingue un falso positivo de corrupción real:** imprimir
`[hex(ord(ch)) for ch in nombre]` y verificar si el codepoint es un carácter Unicode válido
(`0xe9` = 'é', válido) vs. un patrón de mojibake real (secuencias como `0x251c` = carácter de
dibujo de caja, que nunca debería aparecer en un nombre propio). La corrupción real de H-09 sí
tenía codepoints de ese tipo; los falsos positivos no.

**Regla a aplicar:** nunca confiar en cómo se ve un nombre impreso en esta terminal. Siempre
verificar a nivel de codepoint antes de reportar un hallazgo de encoding, y preferir escribir
resultados con nombres a un archivo (`encoding='utf-8'`) y leerlo con la herramienta `Read` en
vez de imprimir directo a la terminal de Git Bash.

## 3. Confundir "está fragmentado" con "el dato falta" — el caso Bogotá

**Qué pasó:** el mapa de zonas de Bogotá se veía disperso/moteado. La primera hipótesis
(razonable) fue "micro-gaps de topología entre polígonos vecinos" y se aplicó un buffer para
cerrarlos — funcionó parcialmente (~50% de reducción de fragmentos). Pero la causa real y
dominante era otra: la fuente de barrios (`Barrio Legalizado`) casi no tiene datos para las
localidades centrales de Bogotá (Los Mártires tenía 1 solo barrio de ~20 reales). El buffer
estaba tratando el síntoma, no la causa.

**Cómo se detectó la causa real:** validar un caso concreto (a pedido del usuario: "validá los
barrios que componen Los Mártires") en vez de seguir ajustando el parámetro del buffer.
Comparar el conteo de barrios por localidad reveló un patrón clarísimo: las localidades
antiguas/consolidadas tenían casi cero barrios, las periféricas de desarrollo informal tenían
cientos — eso apunta a una limitación de cobertura de la fuente, no a un problema geométrico.

**Regla a aplicar:** cuando un ajuste técnico (buffer, tolerancia, filtro) da una mejora
parcial pero no resuelve el problema completo, es una señal de que la causa raíz es otra.
Antes de seguir afinando el parámetro, validar con un caso concreto y chequear si hay un
patrón sistemático en los datos (agrupar/ordenar por la métrica sospechosa) en vez de mirar
solo el promedio o el total.

## 4. Verificar el dato en vivo antes de asumir que un cambio de código surtió efecto

**Qué pasó:** varias veces durante la sesión (KPIs del dashboard, popup→tooltip, fix de
breadcrumb) el código se editó correctamente pero el navegador seguía mostrando el
comportamiento viejo, porque tenía cacheada la página HTML completa (el script es inline, así
que cachear el HTML cachea el JS también). Esto generó confusión — parecía que la corrección
no había funcionado, cuando en realidad nunca se había recargado.

**Cómo se detectó:** inspeccionar `renderKPIs.toString()` (o la función que se acababa de
editar) directamente en la consola del navegador para comparar contra el código fuente actual.

**Regla a aplicar:** después de editar un archivo HTML con `<script>` inline y volver a probarlo
en el navegador, navegar con un parámetro de cache-busting (`?_cb=N`) en vez de solo recargar,
o verificar explícitamente que el código cargado coincide con el archivo antes de diagnosticar
"por qué no funciona".

## 5. Dos fuentes de datos que deberían ser una sola generan bugs de sincronización imposibles de prevenir con matching aproximado

**Qué pasó:** mientras la tabla del dashboard leía de `medfut.dim_geo` y el mapa leía de
`c98_geo`, la única forma de sincronizarlos era por nombre aproximado (`LIKE` bidireccional).
Esto funcionó la mayoría de las veces, pero falló exactamente en el caso donde dos entidades
distintas comparten parte del nombre entre sí ("La Candelaria" en Bogotá y Medellín, con zonas
padre "Centro" y "Centrooriente" — "Centro" es substring literal de "Centrooriente").

**Por qué el matching aproximado nunca iba a ser 100% confiable:** cualquier esquema de
similitud de texto (substring, distancia de edición, etc.) tiene casos límite donde dos
entidades reales colisionan. No es un bug puntual a parchear, es una limitación estructural
del enfoque.

**Cómo se resolvió de raíz:** cuando se migró la tabla para leer de `c98_geo` también (en vez
de `medfut.dim_geo`), dejó de haber necesidad de matching aproximado — tabla y mapa ya
comparten los mismos `id` reales, y la sincronización pasó a ser por igualdad exacta de id.

**Regla a aplicar:** si dos componentes de un sistema necesitan estar sincronizados y pueden
compartir la misma fuente de datos, compartirla — no construir un puente de matching
aproximado entre dos fuentes independientes "porque ya está así". El matching por nombre/texto
entre fuentes distintas debe tratarse como una solución temporal, no definitiva, precisamente
porque los falsos positivos no son un "por si acaso" sino una certeza estadística a medida que
crece el volumen de datos.

## 6. Un entregable que no cuadra con el número esperado no se cierra hasta explicar la diferencia exacta

**Patrón repetido:** en varios puntos de la sesión (barrios de Barranquilla 186/191, Bogotá
573→1203 tras el bug del punto 1, comuna de Bogotá 19/20 por un `INNER JOIN` en vez de
`LEFT JOIN`) el primer resultado no cuadraba con el total esperado. En todos los casos, la
diferencia exacta (no solo "está cerca") llevó directo a la causa: 5 barrios sin localidad
asignada, un bug de padding, una comuna sin barrios excluida por el tipo de join.

**Regla a aplicar:** cuando un conteo no cuadra, no redondear el problema ("está bastante
cerca") ni aceptar el resultado parcial. Calcular la diferencia exacta y explicarla
explícitamente — casi siempre la diferencia exacta es la pista que resuelve la causa en un
paso, en vez de tener que adivinar.
