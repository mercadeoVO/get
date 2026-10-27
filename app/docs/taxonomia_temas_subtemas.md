# Taxonomía de Temas y Subtemas — Preguntas y Respuestas MEDFUT

**Fase 0 — Propuesta de análisis y segmentación.** Documento vivo: se ajustará en la fase
de diseño según feedback de negocio. No implica cambios de esquema ni de código.

Fecha de análisis: 2026-08-26. Fuente: catálogo completo de `import_log_leads_preguntas`
(393 filas) consultado en modo solo-lectura contra `C98_MEDFUT` (127.0.0.1:3307).

---

## 1. Diagnóstico del esquema actual

| Tabla | Categorización existente | Naturaleza |
|---|---|---|
| `import_log_leads_preguntas` | `es_electoral` (TINYINT) | Booleano, autodetectado por `str_contains` sobre ~15 keywords (`candidat`, `votar`, `voto`, `presiden`, `alcald`, `concej`, `senado`, `elecci`, etc. — ver `leads_importer.php:1526-1528`). Un solo valor por pregunta, no editable, no multivaluado. |
| `import_electoral` | `electoral_objetivo` (VARCHAR 60) | Un valor fijo por fila (`intencion_voto`, `simpatia_partido`, `conocimiento_candidato`, `evaluacion_candidato`, `participacion_electoral`, `imagen_candidato`), definido en `leads_import.html` (`ELECTORAL_FIELDS`). No multivaluado. |
| `dim_candidato` | `espectro` (VARCHAR) | Espectro político del candidato (DERECHA…IZQUIERDA…INDEPENDIENTE). Aplica a candidatos, no a preguntas/respuestas. |
| `import_log_leads_respuestas` | — | Ninguna. Solo hereda indirectamente `es_electoral` vía `pregunta_id`. |

**No existe ningún mecanismo de categorización N:M** (una pregunta o respuesta con varias
etiquetas simultáneas). Esta propuesta llena ese vacío para todo lo que no es
Política/Electoral, que se mantiene tal cual por decisión explícita.

### Hallazgos colaterales (no se corrigen en esta fase, quedan documentados)

1. **Falsos positivos en `es_electoral`**: id 397 ("¿con cuáles atributos asocias a alpina?
   (**selecci**ón múltiple)") quedó `es_electoral=1` porque "selecci**ón**" contiene la
   subcadena "elecci". Al menos 1 caso confirmado; sugiere revisar todo el lote de 55 con ojo
   humano en la fase de diseño.
2. **Falsos negativos**: id 180 ("¿Cómo describiría su opinión sobre Álvaro Uribe?") quedó
   `es_electoral=0` pese a tratarse claramente de imagen de una figura política — probablemente
   porque no contiene ninguna de las keywords exactas. El caso más notorio es un bloque
   completo de **6 preguntas sobre Uribe / Centro Democrático (ids 359, 360, 361, 362, 364,
   365)** — "¿tiene imagen favorable o desfavorable de Uribe?", "¿alguna vez ha votado por
   Uribe o por el Centro Democrático?", "¿se consideró Uribista?", etc. — todas con
   `es_electoral=0` pese a ser tan político-partidistas como las que sí quedaron marcadas
   (ids 177–179, 193–203, etc.). Igual con id 292 ("¿Quién cree que va a ganar?", sin objeto
   explícito) e id 111/149/192, que rozan lo político/institucional sin disparar el
   keyword-match.
3. **Clima organizacional interno mezclado con opinión ciudadana**: ids 75–82 están dirigidas
   a *colaboradores* del programa Buen Comienzo ("¿qué tan claras son las orientaciones que
   recibes para tu labor diaria?"), no al público general. Se recomienda un dominio propio
   `interno` para no contaminar los análisis de percepción ciudadana.
4. **Filas basura**: ids 373–376 ("Palabra clave (categoría)", " texto normalizado (auxiliar)",
   " limpieza paso 1 (auxiliar)", "variacion") son columnas auxiliares de un Excel de trabajo,
   importadas por error como si fueran preguntas de encuesta. Candidatas a borrado en la fase
   de diseño, no a categorización.

---

## 2. Modelo de dominios propuesto

```
Dominio: Política / Electoral         ← YA EXISTE, se mantiene intacto y aparte
  (es_electoral, electoral_objetivo, espectro — sin cambios)

Dominio: Social / Marca / Opinión     ← NUEVO, objeto de esta propuesta
  Tema 1: Condición social y económica
  Tema 2: Bienestar emocional y salud mental
  Tema 3: Identidad y cultura
  Tema 4: Control social, seguridad y confianza institucional
  Tema 5: Marca y relación con marca
  Tema 6: Obra pública y gestión institucional
  Tema 7: Opinión y percepción general de país/gobierno

Dominio: Interno                      ← NUEVO, aislado a propósito
  Tema 1: Clima organizacional (colaboradores de programas, no leads/ciudadanía)
```

Una pregunta puede llevar **más de un (tema, subtema)**, incluso de temas distintos dentro
del mismo dominio (ej. una pregunta de EPM puede ser a la vez *Marca → Confianza* y
*Obra pública → Transparencia*). Las respuestas individuales pueden llevar etiquetas propias
adicionales a las heredadas de su pregunta (confirmado con el usuario) — típicamente relevante
en respuestas de texto libre.

---

## 3. Temas y subtemas (dominio Social / Marca / Opinión)

Conteo real tras clasificar el catálogo completo (392 preguntas con texto no vacío):

| Dominio | Tema | # preguntas |
|---|---|---:|
| Social | Marca y relación con marca | 112 |
| Social | Obra pública y gestión institucional | 64 |
| Social | Condición social y económica | 53 |
| Social | Bienestar emocional y salud mental | 36 |
| Social | Identidad y cultura | 33 |
| Social | Control social, seguridad y confianza institucional | 11 |
| Social | Opinión y percepción general de país/gobierno | 8 |
| Interno | Clima organizacional | 8 |
| Excluir (filas auxiliares de Excel) | — | 4 |
| Política / Electoral | Existente, sin cambios | 55 |
| Política / Electoral | Reclasificado en Fase 0 (sección 4) | 8 |
| **Total** | | **392** |

Nota: el id 87 (críticas de Petro a Hidroituango, ver sección 4) ya está incluido en el
conteo de 64 de "Obra pública y gestión institucional" — se resolvió con el usuario y quedó en
Social, no en Política/Electoral.

### Tema 1 — Condición social y económica (53 preguntas)
| Subtema | Qué mide | Ejemplos (id) |
|---|---|---|
| Percepción de clase social | Autoubicación socioeconómica | 1, 215 |
| Costo de vida y gasto cotidiano | Arriendo, mercado, transporte, servicios | 2, 4–6, 8, 204–213 |
| Movilidad social y mérito | Si el esfuerzo/mérito se traduce en resultados | 169–171, 224, 231, 245 |
| Empleo y aspiraciones laborales | Expectativas sobre trabajo y empleador | 172–174, 216–220, 227, 242 |
| Precariedad y endeudamiento | Sacrificios económicos, deudas | 214, 222, 229, 244, 248 |

### Tema 2 — Bienestar emocional y salud mental (36 preguntas, ids 253–289)
| Subtema | Qué mide | Ejemplos (id) |
|---|---|---|
| Soledad y redes de apoyo | Frecuencia de soledad, a quién recurre | 256, 259, 260, 262, 263, 270, 271, 275, 284 |
| Salud mental / terapia | Acceso, barreras, diferenciación clínica | 253, 254, 266, 269, 278, 280, 283 |
| Comparación social (redes) | Efecto de ver éxito ajeno en redes | 255, 261, 265, 273, 277, 289 |
| Manejo emocional | Cómo procesa tristeza, rabia, estrés | 257, 258, 264, 267, 272, 274, 276, 279, 281, 282, 285–287 |
| Fe / espiritualidad | Rol de la fe en la vida | 288 |

### Tema 3 — Identidad y cultura (33 preguntas)
| Subtema | Qué mide | Ejemplos (id) |
|---|---|---|
| Orgullo nacional y regional | Identidad país/región | 40–46, 49–51 |
| Gentrificación e identidad paisa | Migración/turismo extranjero y encarecimiento | 1, 3, 7, 9–13 |
| Narcocultura / mito Escobar | Percepción histórica y cultural | 27–39 |
| Percepción de evolución de la ciudad | Mejora/empeora | 329, 331 |
| Identidad personal (transversal) | Palabras con las que se identifica | 175 |

### Tema 4 — Control social, seguridad y confianza institucional (11 preguntas, ids 105–113, 115–116)
| Subtema | Qué mide | Ejemplos (id) |
|---|---|---|
| Percepción de inseguridad / miedo | Vulnerabilidad, miedo a salir de noche | 105, 106, 113 |
| Relación con policía / autoridad | Vínculo con fuerza pública | 107, 108, 111* |
| Vigilancia vs. privacidad | Aceptación de control a cambio de seguridad | 110, 115, 116 |
| Confianza en la justicia | Efectividad percibida del sistema judicial | 109 |
| Organización comunitaria | Frentes de seguridad ciudadana | 112 |

\* id 111 lleva además doble etiqueta hacia *Opinión general → Confianza en el sistema* (sección 4).

### Tema 5 — Marca y relación con marca (112 preguntas, el más grande)
Subtemas transversales, aplicables a cualquier marca del catálogo:

| Subtema | Qué mide |
|---|---|
| Conocimiento / awareness | ¿Conoce la marca/el programa? |
| Imagen y percepción | Adjetivos, sensaciones, top-of-mind |
| Vínculo emocional y cercanía | Cercanía, pertenencia, identificación |
| Comunicación de marca | Canal, tono, claridad, frecuencia deseada |
| Consumo, compra y recomendación (NPS) | Hábito de consumo, intención de recompra/recomendar |
| Confianza en la marca/institución | Cumplimiento de promesas, credibilidad |

Marcas/entidades concretas presentes (para sub-etiquetar además por marca, como dimensión
ortogonal al subtema):

| Marca/entidad | ids representativos |
|---|---|
| Grupo Bios | 52–58 |
| EPM (marca, comunicación, factura, contenido "Camino al Barrio") | 99–104, 121–123, 145–166, 336, 338–340 |
| IKEA Cali | 181–190 |
| Alpina | 391–400 |
| Alimento para mascotas (marca genérica) | 401–408 |
| J Balvin | 294–303 |
| Bureau Medellín y Antioquia | 136–144 |
| Plaza Mayor Medellín | 341–349 |
| EDU (Empresa de Desarrollo Urbano) | 126–135 |

### Tema 6 — Obra pública y gestión institucional (64 preguntas)
| Subtema | Qué mide | Ejemplos (id) |
|---|---|---|
| Conocimiento del proyecto | ¿Sabe que existe / qué es? | 59–60, 62–64, 84, 91–93, 350–352, 409–410, 418–419, 427–428 |
| Percepción de necesidad / prioridad | ¿Vale la pena invertir en esto? | 67–68, 94, 353–354, 411–413 |
| Impacto y beneficio esperado | ¿A quién beneficia, cómo? | 69, 85, 95, 97, 355–356, 414, 423–424 |
| Confianza en cumplimiento de plazos | ¿Se va a terminar / a tiempo? | 70, 96, 357, 415–416 |
| Transparencia y comunicación oficial | Calidad/claridad de la información pública | 61, 72, 89, 127, 131, 426, 433 |
| Percepción general / naming | Reacción de conjunto, denominación popular vs. oficial | 66, 71, 74, 83, 87, 88, 90, 98, 358, 420–421, 429, 432 |

Proyectos/entidades concretas: Buen Comienzo (jardines infantiles, ids 59–74, 409–417),
Hidroituango (83–90, 150), Estadio Atanasio Girardot (91–98), EDU (126–135), Megacolegios
(350–358), obras barriales sin nombre oficial (418–429, 432–433).

### Tema 7 — Opinión y percepción general de país/gobierno (8 preguntas, ids 47, 114, 155, 167–168, 176, 192, y 111 como etiqueta secundaria)
| Subtema | Qué mide | Ejemplos (id) |
|---|---|---|
| Problemas prioritarios del país | Top-of-mind de preocupaciones | 167 |
| Confianza en el sistema/gobierno | Legitimidad percibida | 114, 168, 176, 192, y 111* |
| Fuentes de información y noticias | Canal por el que se informa | 47, 155 |

\* id 111 es doble etiqueta (primaria en Tema 4 → Control social, ver arriba).

### Dominio aparte — Interno: Clima organizacional (~8 preguntas, ids 75–82)
No se cruza con el dominio Social/Marca/Opinión. Encuesta a colaboradores, no a leads.

---

## 4. Casos ambiguos — resueltos con el usuario

Los 9 casos que habían quedado en el dominio provisional "Revisar" se resolvieron en sesión de
trabajo, consultando en algún caso las respuestas reales para decidir con datos:

| id(s) | Pregunta | Resolución | Base de la decisión |
|---|---|---|---|
| 180 | ¿Cómo describiría su opinión sobre Álvaro Uribe? | **Política / Electoral** | Imagen de figura político-partidista; `es_electoral` debería ser 1. |
| 359, 360, 361, 362, 364, 365 | "¿Tiene imagen favorable o desfavorable de Uribe?", "¿ha votado por Uribe o el CD?", "¿se consideró Uribista?", etc. | **Política / Electoral** | Bloque político-partidista completo; mismo criterio que 180. Falso negativo de `es_electoral` confirmado. |
| 292 | ¿Quién cree que va a ganar? | **Política / Electoral** | Se revisaron las 2,005 respuestas registradas: 15 opciones, todas precandidatos presidenciales colombianos (Abelardo de la Espriella 65.2%, Iván Cepeda 27.7%, Sergio Fajardo 3.7%, resto <1% c/u). Sin ambigüedad una vez vistos los datos. |
| 87 | Sobre las críticas de Petro a Hidroituango, ¿crees que son…? | **Social → Obra pública y gestión institucional → Percepción general / naming** | El eje que mide la pregunta es la percepción del proyecto Hidroituango, no la figura de Petro; se queda con las demás preguntas de Hidroituango. |

Estas 8 preguntas (180 + 6 del bloque Uribe/CD + 292) quedan marcadas en el dataset como
**"Reclasificado en Fase 0"** dentro de Política/Electoral, con una nota de que su columna
`es_electoral` en la base de datos sigue en `0` — la corrección de ese valor es una decisión de
la fase de diseño (no se tocó la BD en esta fase). El dominio "Revisar" queda en 0 preguntas.

### Conteo actualizado tras la resolución

| Dominio | # preguntas |
|---|---:|
| Social | 317 |
| Política / Electoral (55 originales + 8 reclasificadas) | 63 |
| Interno | 8 |
| Excluir | 4 |
| **Total** | **392** |

### Casos menores — también resueltos con el usuario

Los 8 casos restantes con nota de cruce/ambigüedad se revisaron uno por uno. Tres de ellos se
resolvieron con **doble etiqueta** (tema y subtema primario + un segundo tema/subtema
"también"), anticipando cómo se vería `pregunta_tema` cuando exista el modelo N:M real; los
demás se dejaron con un solo tema, unos moviendo de tema y otros manteniéndose donde estaban:

| id | Pregunta (resumen) | Resolución |
|---|---|---|
| 111 | Cualidad de un líder para recuperar autoridad | **Doble etiqueta**: Control social → Relación con policía/autoridad **+** Opinión general → Confianza en el sistema |
| 114 | Cuánta autoridad tiene el Estado | **Movida** de Control social a Opinión y percepción general de país/gobierno → Confianza en el sistema |
| 121 | Quién debería liderar el ahorro de servicios públicos | **Se mantiene** en Marca (EPM/Estado) → Confianza en la marca/institución |
| 148 | Opinión sobre el valor de la factura | **Doble etiqueta**: Marca EPM → Imagen y percepción **+** Condición social → Costo de vida y gasto cotidiano |
| 149 | Influencia de políticos en decisiones de EPM | **Se mantiene** en Marca EPM → Confianza en la marca/institución |
| 192 | Quién tiene más poder real en Colombia | **Se mantiene** en Opinión y percepción general de país/gobierno → Confianza en el sistema |
| 282 | Cosas no dignas por sobrevivir | **Doble etiqueta**: Bienestar emocional → Manejo emocional **+** Condición social → Precariedad y endeudamiento |
| 424 | Para quién creen que hacen la obra (escepticismo) | **Se mantiene** en Obra pública → Percepción general / naming |

No quedan casos con nota de ambigüedad sin resolver en el dataset borrador (además de las 4
filas auxiliares de Excel, que se excluyen del catálogo sin discusión).

---

## 5. Regla de asociación N:M (confirmada con el usuario)

- **`pregunta_tema`**: relación N:M entre `pregunta_id` y (`tema_id`, opcionalmente
  `subtema_id`). Una pregunta puede tener varias etiquetas.
- **`respuesta_tema`**: relación N:M independiente entre una fila de
  `import_log_leads_respuestas` y (`tema_id`, `subtema_id`), para etiquetas *adicionales* a
  las heredadas de la pregunta — relevante sobre todo en respuestas de texto libre que tocan
  un tema no anticipado por la pregunta.
- Ninguna de las dos tablas se crea en esta fase; se diseñan en la fase siguiente.

## 6. Próximos pasos

Los casos ambiguos ya se resolvieron con el usuario (sección 4). Lo que sigue es la Fase de
Diseño, desarrollada en la sección 7. **Nada de esta sección 7 está ejecutado todavía** — es
propuesta de esquema y de flujo, pendiente de aprobación antes de la Fase de Implementación.

## 7. Fase de Diseño — esquema de tablas y actualización en tiempo real

### 7.1 Problema a resolver

La Fase 0 clasificó las 392 preguntas *existentes* hoy en la base de datos. Pero
`leads_importer.php` sigue importando encuestas nuevas, y cada importación puede traer
preguntas que no existen todavía en `import_log_leads_preguntas`. Sin un mecanismo explícito,
esas preguntas nuevas quedarían sin tema/subtema — el catálogo se desactualizaría con cada
importación. El diseño de abajo resuelve esto enganchándose al mismo punto del código donde
hoy se calcula `es_electoral` para preguntas nuevas.

### 7.2 Tablas nuevas propuestas

```sql
-- Catálogo jerárquico de dominio → tema → subtema
CREATE TABLE dim_tema (
    id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dominio     VARCHAR(30)  NOT NULL COMMENT 'Social | Interno  (Política/Electoral NO entra aquí, sigue aparte)',
    tema        VARCHAR(120) NOT NULL,
    subtema     VARCHAR(150) NOT NULL,
    codigo      VARCHAR(10)  NOT NULL COMMENT 'SOC-1..SOC-7, INT-1 (ver tabla de códigos, 7.3)',
    keywords    JSON NULL COMMENT 'Palabras/frases que dispara el clasificador automático (7.4)',
    UNIQUE KEY uk_tema_subtema (tema, subtema)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- N:M pregunta ↔ tema. Una pregunta puede tener varias filas (doble etiqueta, sección 4).
CREATE TABLE pregunta_tema (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    pregunta_id       SMALLINT UNSIGNED NOT NULL,
    tema_id           SMALLINT UNSIGNED NOT NULL,
    origen            ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    pendiente_revision TINYINT(1) NOT NULL DEFAULT 1,
    creado_en         DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pregunta_tema (pregunta_id, tema_id),
    CONSTRAINT fk_pt_pregunta FOREIGN KEY (pregunta_id) REFERENCES import_log_leads_preguntas(id) ON DELETE CASCADE,
    CONSTRAINT fk_pt_tema     FOREIGN KEY (tema_id)     REFERENCES dim_tema(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- N:M respuesta ↔ tema. Etiquetas ADICIONALES a las heredadas de la pregunta (ver 7.6).
CREATE TABLE respuesta_tema (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    respuesta_id INT NOT NULL,
    tema_id      SMALLINT UNSIGNED NOT NULL,
    origen       ENUM('auto','manual') NOT NULL DEFAULT 'manual',
    creado_en    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_respuesta_tema (respuesta_id, tema_id),
    CONSTRAINT fk_rt_respuesta FOREIGN KEY (respuesta_id) REFERENCES import_log_leads_respuestas(id) ON DELETE CASCADE,
    CONSTRAINT fk_rt_tema      FOREIGN KEY (tema_id)      REFERENCES dim_tema(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Política/Electoral **no** entra en `dim_tema` — sigue viviendo en `es_electoral` +
`electoral_objetivo`, tal como se acordó en la Fase 0. `dim_tema.dominio` solo toma los
valores `Social` e `Interno`.

### 7.3 Códigos de clasificación (para poblar `dim_tema`)

| Código | Dominio | Tema |
|---|---|---|
| SOC-1 | Social | Condición social y económica |
| SOC-2 | Social | Bienestar emocional y salud mental |
| SOC-3 | Social | Identidad y cultura |
| SOC-4 | Social | Control social, seguridad y confianza institucional |
| SOC-5 | Social | Marca y relación con marca |
| SOC-6 | Social | Obra pública y gestión institucional |
| SOC-7 | Social | Opinión y percepción general de país/gobierno |
| INT-1 | Interno | Clima organizacional |

### 7.4 Actualización en tiempo real al importar una encuesta nueva

Hoy, `leads_importer.php` (líneas ~1526-1528, ~1774-1779) ya hace esto para `es_electoral`
cuando encuentra una pregunta que no existe en `import_log_leads_preguntas`:

```php
foreach ($electoralKeywords as $kw) {
    if (str_contains($pregLower, $kw)) { $isElectoral = 1; break; }
}
$stmtInsPreg->execute([$pregNorm, $isElectoral]);
```

Se propone engancharse en el mismo punto, inmediatamente después de insertar la pregunta
nueva:

1. **Clasificar automáticamente**: recorrer `dim_tema.keywords` (una lista de palabras/frases
   por tema/subtema, cargada una vez en memoria) contra el texto de la pregunta nueva, igual
   que hoy se hace con `$electoralKeywords`. Puede matchear más de un tema (múltiples filas en
   `pregunta_tema`).
2. **Insertar en `pregunta_tema`** con `origen='auto'` y `pendiente_revision=1` para cada match
   — nunca se deja sin tema, pero tampoco se trata como definitivo.
3. **No bloquear la importación**: los leads y respuestas quedan disponibles de inmediato,
   igual que hoy. La clasificación automática es best-effort, no un gate.
4. Si el clasificador no encuentra ningún match (pregunta genuinamente nueva/atípica), se
   inserta igual una fila "sin tema, pendiente" para que aparezca en la cola de revisión (7.5)
   en vez de desaparecer silenciosamente del catálogo.

Esto es deliberadamente el mismo patrón que `es_electoral` ya usa — mismo punto del código,
misma técnica — para no introducir un segundo mecanismo paralelo de detección.

### 7.5 Cola de revisión manual (UI)

El keyword-matching de `es_electoral` ya demostró tener falsos positivos/negativos (sección 1,
hallazgo 1-2). El mismo riesgo aplica al clasificador de temas. Por eso la auto-clasificación
nunca es la última palabra:

- Panel nuevo (o sección añadida a `leads_import.html`) que lista las preguntas con
  `pendiente_revision=1`, mostrando: texto de la pregunta, tema/subtema sugerido, y controles
  para **confirmar**, **corregir** (cambiar tema/subtema) o **agregar un tema adicional**
  (doble etiqueta, como en los ids 111/148/282 de la Fase 0).
- Al confirmar o corregir, `pendiente_revision` pasa a `0` y `origen` a `'manual'` si hubo
  corrección.
- Los dashboards de analytics (`leads_analytics.html`) pueden decidir si excluyen las filas
  `pendiente_revision=1` de los reportes "oficiales" por tema, o las incluyen con una marca
  visual de "sugerido, sin confirmar" — decisión de negocio a tomar en la Fase de
  Implementación.

### 7.6 Migración inicial (seed) con lo ya decidido en la Fase 0

El dataset borrador de 392 preguntas (incluyendo las 8 reclasificadas a Política/Electoral, las
3 dobles etiquetas y la resolución de los 8 casos menores, todos de la sección 4) se carga como
seed inicial de `pregunta_tema` con `origen='manual'` y `pendiente_revision=0` — ya pasaron por
revisión humana en esta conversación, no necesitan pasar de nuevo por la cola.

Adicionalmente, en esta migración:
- Las 4 filas basura (ids 373, 374, 375, 376) se **eliminan** de
  `import_log_leads_preguntas` (con su cascada en `import_log_leads_respuestas` si tuvieran
  respuestas asociadas) en vez de clasificarse.
- Las 8 preguntas del dominio Interno (ids 75-82) se clasifican con `INT-1`, quedando
  disponibles para excluirlas explícitamente de los análisis de opinión ciudadana en
  `leads_analytics.html`.
- Los ids 180, 292, 359, 360, 361, 362, 364, 365 quedan sin fila en `pregunta_tema` (porque
  pertenecen a Política/Electoral, que no usa esta tabla) — su corrección real es actualizar
  `es_electoral=1` en `import_log_leads_preguntas`, decisión que se toma en la Fase de
  Implementación para no tocar la BD todavía.

### 7.7 Estado de implementación

Ejecutado contra `C98_MEDFUT` (127.0.0.1:3307) el 2026-08-26:

- `dim_tema`, `pregunta_tema`, `respuesta_tema` creadas según el DDL de 7.2.
- `dim_tema` poblada con 37 filas (uno por cada combinación real de tema/subtema
  identificada en la Fase 0), cada una con su código jerárquico (`SOC-1.1`…`SOC-7.3`, `INT-1.1`)
  y una lista de keywords/frases para el clasificador automático.
- `pregunta_tema` poblada con 328 filas (`origen='manual'`, `pendiente_revision=0`) — el seed
  de las 392 preguntas de la Fase 0: 317 en Social + 8 en Interno + 3 filas extra por las
  dobles etiquetas (111, 148, 282).
- `es_electoral` corregido a `1` en los 8 ids reclasificados a Política/Electoral (180, 292,
  359, 360, 361, 362, 364, 365) — de 55 a 63 preguntas electorales.
- Las 4 filas basura (373-376) eliminadas de `import_log_leads_preguntas` (no tenían
  respuestas asociadas, se verificó antes de borrar).
- `leads_importer.php` modificado: en el mismo punto donde ya se calcula `es_electoral` para
  una pregunta nueva (líneas ~1529-1540 y ~1779-1792), se agregó el clasificador automático de
  tema/subtema. Solo corre sobre preguntas realmente nuevas (`$stmtInsPreg->rowCount() > 0`) y
  no electorales; inserta en `pregunta_tema` con `origen='auto'`, `pendiente_revision=1` para
  cada tema cuyo keyword matchee. Si ningún keyword matchea, no inserta nada — la pregunta
  queda sin fila en `pregunta_tema`, lo cual es la señal de "pendiente de revisión" (ver 7.5).

**Probado con 5 preguntas simuladas** (no se importó un Excel real): 2 matchearon un subtema
correctamente (marca/recomendación, percepción de clase social) y 3 no matchearon ninguno
(inseguridad, obra pública genérica, una pregunta fuera de dominio) — quedan como pendientes,
que es el comportamiento esperado del diseño conservador (prioriza no-clasificar sobre
clasificar mal). Las keywords son frases literales extraídas de las preguntas ya vistas, por lo
que el clasificador irá mejorando su cobertura a medida que se amplíen con lo que vaya
apareciendo en la cola de revisión — no se intentó maximizar cobertura desde el día uno para no
repetir el problema de falsos positivos de `es_electoral`.

**Cola de revisión — dos superficies, mismo backend**: en vez de solo un panel aparte, la
revisión quedó en dos lugares que comparten los mismos endpoints (`registrar_preguntas` /
`temas_guardar`):

1. **Integrada al wizard de importación** (`leads_import.html`) — nuevo paso **"Temas"**
   entre "Columnas" y "Confirmar" (stepper de 5 pasos). Al llegar aquí, el frontend junta los
   headers de las columnas marcadas como EAV en *esta* importación y llama a
   `action=registrar_preguntas`, que las registra en el catálogo si son nuevas y devuelve su
   clasificación (ya existente, auto-sugerida, o ninguna). El usuario confirma/corrige ahí
   mismo, antes de pulsar "Confirmar e importar" — la revisión ocurre en el momento en que
   tiene más contexto sobre la encuesta que está subiendo, no después.
2. **Panel standalone** ([leads_temas_pendientes.html](../leads_temas_pendientes.html)),
   accesible desde un enlace en el topbar y desde un stat-box "🗂️ Temas pendientes" en el
   dashboard del importador — para lo que quedó sin confirmar en el paso 1 (el usuario puede
   avanzar sin clasificar todo), o para preguntas que entraron por otra vía (ej. si se llama a
   `action=import` directamente sin pasar por el wizard, activando el hook automático de 7.4).

Los tres endpoints en `leads_importer.php`: `registrar_preguntas` (usado por el paso del
wizard), `temas_pendientes` (usado por el panel standalone) y `temas_guardar` (usado por
ambos).

**Mejoras al panel standalone** (2026-09-01):
- La pregunta se resalta (mayor peso tipográfico, color amarillo) — es lo primero que debe leer
  el analista, antes que cualquier badge o botón.
- Botón "👁️ Ver respuestas" por pregunta — trae bajo demanda (`action=respuestas_muestra`) la
  distribución real de respuestas ya registradas para esa pregunta, con conteo y porcentaje.
  Da contexto real para decidir el tema en vez de adivinar solo por el enunciado.
- Cuando ni el keyword-matching exacto (7.4) encuentra nada, se calcula una **sugerencia
  débil** por similitud de tokens entre la pregunta y (tema + subtema + keywords) de cada fila
  de `dim_tema` (función `sugerirTemasDebil`, Jaccard simple sobre tokens sin stopwords). Se
  muestra como chips clicables con su score, siempre marcada como "no confirmado".
- Si ninguna sugerencia aplica, `action=crear_tema` permite dar de alta un tema/subtema nuevo
  directamente desde el panel (dominio, tema, subtema, keywords opcionales) — genera el código
  jerárquico automáticamente (reutiliza el prefijo si el tema ya existe, o abre uno nuevo) y
  asigna la pregunta al tema recién creado en el mismo paso.

**Pendiente**: por ahora se puede seguir consultando por SQL directamente si hace falta:

```sql
-- Preguntas no-electorales sin ninguna clasificación de tema todavía
SELECT p.id, p.pregunta
FROM import_log_leads_preguntas p
WHERE p.es_electoral = 0
  AND NOT EXISTS (SELECT 1 FROM pregunta_tema pt WHERE pt.pregunta_id = p.id);

-- Clasificaciones automáticas pendientes de confirmar
SELECT p.id, p.pregunta, dt.codigo, dt.tema, dt.subtema
FROM pregunta_tema pt
JOIN import_log_leads_preguntas p ON p.id = pt.pregunta_id
JOIN dim_tema dt ON dt.id = pt.tema_id
WHERE pt.pendiente_revision = 1;
```

### 7.8 Respuestas: por qué `respuesta_tema` no se rellena en masa

`respuesta_tema` es para etiquetas *adicionales* a las que la respuesta ya hereda de su
pregunta (vía `pregunta_id` → `pregunta_tema`) — no una duplicación de esa herencia. Poblarla
para las ~cientos de miles de filas existentes en `import_log_leads_respuestas` no aporta valor
por sí solo: la mayoría de respuestas (escalas Likert, selección única) no necesitan etiqueta
propia porque no dicen nada que la pregunta no diga ya. Se propone poblarla bajo demanda,
priorizando respuestas de texto libre cuando un analista las revise, no como parte de una
migración masiva.

### 7.9 Temas agregados después del seed inicial

El catálogo `dim_tema` crece con el uso — vía el panel de revisión (`action=crear_tema`) o
directamente cuando hace falta. Registro de lo añadido después del seed de 37 subtemas:

| Código | Dominio | Tema | Subtema | Origen |
|---|---|---|---|---|
| SOC-8.1 | Social | Riesgo climático y ambiental | Racionamiento y escasez de recursos | Creado desde el panel al clasificar la pregunta 435 (Fenómeno de El Niño), sin match ni siquiera débil en el catálogo original. |
| SOC-9.1 | Social | Comportamiento electoral | Favorabilidad de candidatos | Pedido explícito del usuario; asignado a 14 preguntas del patrón "¿tiene una imagen positiva o negativa de [candidato]?" (ids 466-479: Petro, Abelardo de la Espriella, Fico, Galán, Char, Uribe, Eder, Restrepo, Oviedo, Rendón, Cabal, Cepeda, Nubia Córdoba, Juliana Gutiérrez). |
| SOC-9.2 | Social | Comportamiento electoral | Participación electoral | Pedido explícito del usuario. |

**Otro falso negativo masivo de `es_electoral`, corregido**: las 14 preguntas de ids 466-479
tenían todas `es_electoral=0` pese a preguntar explícitamente por la imagen de una figura
política real — el mismo patrón que el bloque Uribe/CD de la sección 4, aquí a mayor escala.
Se corrigió a `es_electoral=1` (2026-09-09): de 63 a 77 preguntas electorales en total. Estas
14 preguntas quedan entonces con **doble clasificación deliberada**: `es_electoral=1` (dominio
Política/Electoral) y una fila en `pregunta_tema` apuntando a `SOC-9.1` (dominio Social) — la
excepción documentada arriba, mantenida a pedido explícito del usuario pese a que el principio
general de 7.2 es que ambos dominios no se mezclen.

**Nota sobre SOC-9 (Comportamiento electoral):** este tema es, por contenido, más cercano al
dominio Política/Electoral que a Social/Interno — y la sección 7.2 fija como principio de
diseño que Política/Electoral no entra en `dim_tema` (sigue viviendo en `es_electoral` /
`electoral_objetivo`, sin doble contabilidad). Se creó de todas formas bajo dominio `Social`
por ser la única opción coherente dentro de `dim_tema` (no es una pregunta de clima laboral).
Implicación práctica: el clasificador automático de 7.4 podría ahora sugerir `SOC-9.x` para
preguntas que en realidad deberían marcarse `es_electoral=1` — vale la pena revisar con más
atención las sugerencias automáticas que caigan en este tema específico durante la Fase de
Implementación en curso, y decidir si conviene fusionarlo más adelante con el dominio
electoral existente o mantenerlo como excepción documentada.
