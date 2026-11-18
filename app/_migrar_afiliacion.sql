-- Normaliza import_log_leads.afiliacion_politica a 6 valores:
-- sin_afiliacion, derecha, centro_derecha, centro, centro_izquierda, izquierda.
-- Mismo mapeo que normalizeAfiliacion() en leads_importer.php. Idempotente.
UPDATE import_log_leads SET afiliacion_politica = CASE
    WHEN afiliacion_politica LIKE 'sin_afiliaci%'                          THEN 'sin_afiliacion'
    WHEN afiliacion_politica IN ('ninguna','ninguno')                      THEN 'sin_afiliacion'
    WHEN afiliacion_politica IN ('de_derecha','uribista','fiquista')       THEN 'derecha'
    WHEN afiliacion_politica = 'de_centro'                                 THEN 'centro'
    WHEN afiliacion_politica IN ('de_izquierda','petrista')                THEN 'izquierda'
    WHEN afiliacion_politica IN ('antiuribista','antipetrista','antifiquista') THEN 'sin_afiliacion'
    ELSE afiliacion_politica END
WHERE afiliacion_politica IS NOT NULL
  AND BINARY afiliacion_politica NOT IN ('sin_afiliacion','derecha','centro_derecha','centro','centro_izquierda','izquierda');
