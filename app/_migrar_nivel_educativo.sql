-- Normaliza import_log_leads.nivel_educativo a 4 valores: universitario, tecnico/tecnologia, bachiller, primaria.
-- Mismo mapeo que normalizeNivelEducativo() en leads_importer.php. Idempotente.
UPDATE import_log_leads SET nivel_educativo = CASE
    WHEN nivel_educativo LIKE 'no%termin%universidad%' THEN 'bachiller'
    WHEN nivel_educativo LIKE 'no%termin%'             THEN 'primaria'
    WHEN nivel_educativo LIKE 't_cnico%' OR nivel_educativo LIKE 'tecnolog%' THEN 'tecnico/tecnologia'
    WHEN nivel_educativo LIKE 'universit%' OR nivel_educativo = 'posgrado'   THEN 'universitario'
    WHEN nivel_educativo IN ('secundaria','bachiller')  THEN 'bachiller'
    WHEN nivel_educativo LIKE 'b_sica%' OR nivel_educativo = 'primaria'      THEN 'primaria'
    ELSE NULL END
WHERE nivel_educativo IS NOT NULL
  AND BINARY nivel_educativo NOT IN ('universitario','tecnico/tecnologia','bachiller','primaria');
