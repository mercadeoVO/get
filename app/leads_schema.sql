-- ============================================================
--  GET · Schema de ingesta de leads Meta Ads (v3 — actualizado)
--  Ejecutar en phpMyAdmin o: mysql -u root -P 3307 C98_GET < leads_schema.sql
--
--  NOTA: dim_geo fue ELIMINADA. El campo id_geo es VARCHAR(15) con código
--  DANE jerárquico que referencia c98_geo (base de datos separada en el
--  mismo servidor). No hay FK hacia c98_geo — se valida a nivel aplicación.
--
--  Cadena de eliminación (CASCADE):
--    import_log
--      → import_log_leads           (CASCADE)
--      → import_electoral           (CASCADE)
--    import_log_leads
--      → import_log_leads_respuestas (CASCADE)
-- ============================================================

USE C98_GET;

-- ── 1. Log de importaciones ───────────────────────────────────
CREATE TABLE IF NOT EXISTS import_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    archivo       VARCHAR(255) NOT NULL,
    hoja          VARCHAR(100) NOT NULL,
    importado_en  DATETIME DEFAULT CURRENT_TIMESTAMP,
    filas_proc    INT DEFAULT 0  COMMENT 'Filas leídas del Excel',
    filas_ins     INT DEFAULT 0  COMMENT 'Leads nuevos insertados',
    filas_dup     INT DEFAULT 0  COMMENT 'Leads duplicados (ya existían)',
    filas_err     INT DEFAULT 0  COMMENT 'Filas con error o ID inválido',
    notas         TEXT NULL,
    url_archivo   VARCHAR(500) NULL,
    url_carpeta   VARCHAR(500) NULL,
    etapa         VARCHAR(50)  DEFAULT 'importado',
    tipo          VARCHAR(20)  DEFAULT 'leads'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Leads (tabla principal) ────────────────────────────────
--   PK = id VARCHAR(64) — soporta Meta IDs, cédulas, UUIDs, etc.
--   id_geo VARCHAR(15) — código DANE jerárquico → c98_geo (sin FK explícita)
--     Longitudes por nivel:
--       2  → Departamento    (ej. '05')
--       5  → Municipio       (ej. '05001')
--       8  → Zona            (ej. '05001Z01')
--      11  → Comuna/Localidad (ej. '05001Z01C01')
--      15  → Barrio          (ej. '05001Z01C01B001')
CREATE TABLE IF NOT EXISTS import_log_leads (
    id                  VARCHAR(64) NOT NULL PRIMARY KEY COMMENT 'Lead ID — deduplicación',
    created_time        DATETIME NULL,
    ad_id               VARCHAR(100) NULL,
    ad_name             VARCHAR(255) NULL,
    adset_id            VARCHAR(100) NULL,
    adset_name          VARCHAR(255) NULL,
    campaign_id         VARCHAR(100) NULL,
    campaign_name       VARCHAR(255) NULL,
    form_id             VARCHAR(100) NULL,
    form_name           VARCHAR(255) NULL,
    is_organic          TINYINT(1) DEFAULT 0,
    platform            VARCHAR(10) NULL        COMMENT 'fb|ig|an|other',
    nombre              VARCHAR(255) NULL,
    email               VARCHAR(255) NULL,
    telefono            VARCHAR(50)  NULL,
    sexo                VARCHAR(20)  NULL,
    rango_edad          VARCHAR(20)  NULL,
    estrato             TINYINT NULL,
    afiliacion_politica VARCHAR(100) NULL,
    nivel_educativo     VARCHAR(100) NULL,
    id_geo              VARCHAR(15)  NULL       COMMENT 'Código DANE c98_geo (2/5/8/11/15 chars). Única fuente de verdad geo — no se persiste texto crudo (departamento/subregión/municipio/zona/comuna/barrio/región); se resuelve en memoria al importar y se descarta. Ver import_log_leads_geo_bkp para el histórico previo a esta simplificación.',
    import_id           INT NOT NULL,
    KEY idx_campaign    (campaign_id),
    KEY idx_form        (form_id),
    KEY idx_import      (import_id),
    KEY idx_email       (email),
    KEY idx_platform    (platform),
    KEY idx_id_geo      (id_geo),
    CONSTRAINT fk_lead_import
        FOREIGN KEY (import_id) REFERENCES import_log(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Catálogo de preguntas dinámicas ───────────────────────
CREATE TABLE IF NOT EXISTS import_log_leads_preguntas (
    id         SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pregunta   VARCHAR(500) NOT NULL,
    import_ids JSON NULL    COMMENT 'Array de import_ids donde aparece esta pregunta',
    UNIQUE KEY uk_pregunta (pregunta(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Respuestas EAV (preguntas dinámicas de formulario) ─────
CREATE TABLE IF NOT EXISTS import_log_leads_respuestas (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    import_id    INT NULL,
    lead_id      VARCHAR(64) NOT NULL,
    pregunta_id  SMALLINT UNSIGNED NULL,
    respuesta    TEXT NULL,
    KEY idx_lead      (lead_id),
    KEY idx_pregunta  (pregunta_id),
    KEY idx_import    (import_id),
    CONSTRAINT fk_resp_lead
        FOREIGN KEY (lead_id) REFERENCES import_log_leads(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_resp_pregunta
        FOREIGN KEY (pregunta_id) REFERENCES import_log_leads_preguntas(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Resultados electorales normalizados ────────────────────
--   Una fila por (importación × pregunta × candidato)
CREATE TABLE IF NOT EXISTS import_electoral (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    import_id          INT NOT NULL,
    id_participacion   INT NULL,
    electoral_objetivo VARCHAR(60)  NOT NULL COMMENT 'intencion_voto | imagen_candidato | etc.',
    pregunta           VARCHAR(500) NOT NULL COMMENT 'Header de columna del Excel',
    id_candidato       INT NULL,
    total_respuestas   INT NOT NULL DEFAULT 0,
    creado_en          DATETIME DEFAULT NOW(),
    CONSTRAINT fk_elec_import
        FOREIGN KEY (import_id) REFERENCES import_log(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_elec_participacion
        FOREIGN KEY (id_participacion) REFERENCES dim_participacion(id_participacion)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_elec_candidato
        FOREIGN KEY (id_candidato) REFERENCES dim_candidato(id_candidato)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Vista resumen de importaciones ────────────────────────
CREATE OR REPLACE VIEW v_import_resumen AS
SELECT
    il.id,
    il.archivo,
    il.hoja,
    il.tipo,
    il.importado_en,
    il.filas_proc,
    il.filas_ins,
    il.filas_dup,
    il.filas_err,
    il.notas,
    il.etapa,
    il.url_carpeta,
    il.url_archivo,
    COUNT(DISTINCT l.id)  AS leads_en_bd,
    COUNT(DISTINCT e.id)  AS registros_electorales
FROM import_log il
LEFT JOIN import_log_leads l   ON l.import_id = il.id
LEFT JOIN import_electoral  e  ON e.import_id = il.id
GROUP BY il.id
ORDER BY il.importado_en DESC;
