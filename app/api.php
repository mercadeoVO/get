<?php
// ============================================================
//  GET · API REST local
//  URL base: http://localhost/get/api.php?endpoint=XXX
//
//  Endpoints disponibles:
//    partidos
//    geografia              [?tipo=MUNICIPIO|COMUNA|CORREGIMIENTO]
//    candidatos
//    candidaturas           [?cargo=ALCALDE|PRESIDENTE] [?anio=2026]
//    votos                  [?anio=2026] [?cargo=PRESIDENTE]
//    encuesta               [?candidato=clave]
//    participacion
//    resumen_presidencial
//    resumen_alcaldia
// ============================================================

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function json_out($data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
function error_out(string $msg, int $code = 400): void {
    http_response_code($code);
    json_out(['error' => $msg]);
}

$endpoint = $_GET['endpoint'] ?? '';

try {
    $db = getDB();

    switch ($endpoint) {

        // ── 1. Partidos ────────────────────────────────────
        case 'partidos':
            json_out($db->query(
                "SELECT * FROM dim_partido ORDER BY id_partido"
            )->fetchAll());

        // ── 2. Geografía — usa dim_geo (DIVIPOLA) ──────────
        case 'geografia':
            $tipo = strtoupper($_GET['tipo'] ?? '');
            // Mapear tipo_geo legacy → nivel en dim_geo
            $nivelMap = ['MUNICIPIO'=>4,'CORREGIMIENTO'=>4,'COMUNA'=>6,'BARRIO'=>7];
            $sql = "SELECT id AS id_geo,
                           COALESCE(nom_mpio, nom_dpto, nom_subregion, 'Sin nombre') AS municipio,
                           CASE nivel WHEN 4 THEN 'MUNICIPIO' WHEN 6 THEN 'COMUNA'
                                      WHEN 7 THEN 'BARRIO' ELSE 'REGION' END AS tipo_geo,
                           nivel, nom_dpto, nom_subregion, nom_zona, nom_comuna, nom_barrio,
                           lat, lon
                    FROM dim_geo";
            if ($tipo && isset($nivelMap[$tipo])) {
                $sql .= " WHERE nivel = " . $nivelMap[$tipo];
            }
            $sql .= " ORDER BY id";
            json_out($db->query($sql)->fetchAll());

        // ── 3. Candidatos ──────────────────────────────────
        case 'candidatos':
            json_out($db->query("
                SELECT c.id_candidato, c.nombre_completo, c.candidato_key,
                       c.espectro, p.nombre_partido, p.sigla
                FROM dim_candidato c
                LEFT JOIN dim_partido p ON p.id_partido = c.id_partido
                ORDER BY c.id_candidato
            ")->fetchAll());

        // ── 4. Candidaturas ────────────────────────────────
        case 'candidaturas':
            $cargo = $_GET['cargo'] ?? null;
            $anio  = $_GET['anio']  ?? null;
            $sql = "
                SELECT dc.id_candidatura, dc.nombre_corto, dc.cargo,
                       dc.anio_eleccion, dc.vuelta, dc.tipo_eleccion,
                       c.nombre_completo, c.candidato_key, c.espectro,
                       p.nombre_partido, p.sigla
                FROM dim_candidatura dc
                JOIN dim_candidato c ON c.id_candidato = dc.id_candidato
                LEFT JOIN dim_partido p ON p.id_partido = c.id_partido
                WHERE 1=1";
            if ($cargo) $sql .= " AND dc.cargo = "       . $db->quote($cargo);
            if ($anio)  $sql .= " AND dc.anio_eleccion = " . (int)$anio;
            $sql .= " ORDER BY dc.anio_eleccion DESC, dc.cargo, dc.id_candidatura";
            json_out($db->query($sql)->fetchAll());

        // ── 5. Votos por geo ───────────────────────────────
        case 'votos':
            $anio  = (int)($_GET['anio']  ?? 2026);
            $cargo = $db->quote($_GET['cargo'] ?? 'PRESIDENTE');
            try {
                json_out($db->query("
                    SELECT g.nom_mpio AS geo,
                           CASE g.nivel WHEN 4 THEN 'MUNICIPIO' WHEN 6 THEN 'COMUNA'
                                        WHEN 7 THEN 'BARRIO' ELSE 'REGION' END AS tipo_geo,
                           c.nombre_completo AS candidato, c.candidato_key,
                           dc.nombre_corto, c.espectro,
                           p.sigla AS partido,
                           fv.votos, fv.pct, fv.total_validos, fv.fuente
                    FROM fact_votos fv
                    JOIN dim_geo         g  ON g.id               = fv.id_geo
                    JOIN dim_candidatura dc ON dc.id_candidatura   = fv.id_candidatura
                    JOIN dim_candidato   c  ON c.id_candidato      = dc.id_candidato
                    LEFT JOIN dim_partido p ON p.id_partido        = c.id_partido
                    WHERE dc.anio_eleccion = $anio AND dc.cargo = $cargo
                    ORDER BY g.id, fv.votos DESC
                ")->fetchAll());
            } catch (\Exception $e) { json_out([]); }

        // ── 6. Encuesta intención de voto alcaldía ─────────
        case 'encuesta':
            $candidato = $_GET['candidato'] ?? null;
            $sql = "
                SELECT g.nom_mpio AS geo,
                       CASE g.nivel WHEN 4 THEN 'MUNICIPIO' WHEN 6 THEN 'COMUNA'
                                    WHEN 7 THEN 'BARRIO' ELSE 'REGION' END AS tipo_geo,
                       c.nombre_completo AS candidato, c.candidato_key,
                       dc.nombre_corto, c.espectro,
                       p.sigla AS partido,
                       fe.sexo, fe.rango_edad, fe.n
                FROM fact_encuesta_agg fe
                JOIN dim_geo         g  ON g.id            = fe.id_geo
                JOIN dim_candidato   c  ON c.candidato_key = fe.candidato
                JOIN dim_candidatura dc ON dc.id_candidato  = c.id_candidato
                  AND dc.cargo = 'ALCALDE' AND dc.anio_eleccion = 2026
                LEFT JOIN dim_partido p ON p.id_partido = c.id_partido
                WHERE 1=1";
            if ($candidato) $sql .= " AND fe.candidato = " . $db->quote($candidato);
            $sql .= " ORDER BY g.id, fe.candidato, fe.sexo, fe.rango_edad";
            try { json_out($db->query($sql)->fetchAll()); }
            catch (\Exception $e) { json_out([]); }

        // ── 7. Participación histórica ─────────────────────
        case 'participacion':
            json_out($db->query("
                SELECT COALESCE(g.nom_mpio, g.nom_dpto, 'Sin geo') AS municipio,
                       dp.anio, dp.tipo_eleccion, dp.vuelta,
                       dp.potencial, dp.total_votantes, dp.pct_participacion
                FROM dim_participacion dp
                LEFT JOIN dim_geo g ON g.id = dp.id_geo
                ORDER BY dp.anio, dp.tipo_eleccion, dp.vuelta
            ")->fetchAll());

        // ── 8. Resumen presidencial 2026 por geo ──────────
        case 'resumen_presidencial':
            try {
                json_out($db->query("
                    SELECT g.nom_mpio AS geo,
                           c.candidato_key,
                           dc.nombre_corto AS candidato,
                           c.espectro,
                           p.sigla AS partido,
                           SUM(fv.votos) AS total_votos,
                           ROUND(
                               SUM(fv.votos) /
                               SUM(SUM(fv.votos)) OVER (PARTITION BY fv.id_geo)
                               * 100, 2
                           ) AS pct_geo
                    FROM fact_votos fv
                    JOIN dim_candidatura dc ON dc.id_candidatura = fv.id_candidatura
                    JOIN dim_candidato   c  ON c.id_candidato    = dc.id_candidato
                    LEFT JOIN dim_partido p  ON p.id_partido      = c.id_partido
                    JOIN dim_geo         g  ON g.id               = fv.id_geo
                    WHERE dc.anio_eleccion = 2026 AND dc.cargo = 'PRESIDENTE'
                    GROUP BY fv.id_geo, dc.id_candidatura, g.nom_mpio,
                             c.candidato_key, dc.nombre_corto, c.espectro, p.sigla
                    ORDER BY g.nom_mpio, total_votos DESC
                ")->fetchAll());
            } catch (\Exception $e) { json_out([]); }

        // ── 9. Resumen encuesta alcaldía por geo ──────────
        case 'resumen_alcaldia':
            try {
                json_out($db->query("
                    SELECT g.nom_mpio AS geo,
                           fe.candidato AS candidato_key,
                           dc.nombre_corto AS candidato,
                           c.espectro,
                           p.sigla AS partido,
                           SUM(fe.n) AS total_n,
                           ROUND(
                               SUM(fe.n) /
                               SUM(SUM(fe.n)) OVER (PARTITION BY fe.id_geo)
                               * 100, 2
                           ) AS pct_intencion
                    FROM fact_encuesta_agg fe
                    JOIN dim_geo         g  ON g.id            = fe.id_geo
                    JOIN dim_candidato   c  ON c.candidato_key = fe.candidato
                    JOIN dim_candidatura dc ON dc.id_candidato  = c.id_candidato
                      AND dc.cargo = 'ALCALDE' AND dc.anio_eleccion = 2026
                    LEFT JOIN dim_partido p ON p.id_partido = c.id_partido
                    GROUP BY fe.id_geo, fe.candidato, g.nom_mpio,
                             dc.nombre_corto, c.espectro, p.sigla
                    ORDER BY g.nom_mpio, total_n DESC
                ")->fetchAll());
            } catch (\Exception $e) { json_out([]); }

        // ── Default: índice de endpoints ───────────────────
        default:
            json_out([
                'api'       => 'GET Electoral API v1.0',
                'status'    => 'ok',
                'endpoints' => [
                    'partidos',
                    'geografia              [?tipo=MUNICIPIO|COMUNA|CORREGIMIENTO]',
                    'candidatos',
                    'candidaturas           [?cargo=ALCALDE|PRESIDENTE] [?anio=2026]',
                    'votos                  [?anio=2026] [?cargo=PRESIDENTE]',
                    'encuesta               [?candidato=clave]',
                    'participacion',
                    'resumen_presidencial',
                    'resumen_alcaldia',
                ],
                'ejemplo' => 'http://localhost/get/api.php?endpoint=resumen_presidencial'
            ]);
    }

} catch (PDOException $e) {
    error_out('DB error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    error_out('Error: ' . $e->getMessage(), 500);
}
