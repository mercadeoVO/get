<?php
// ============================================================
//  GET · Detección de subtemas parecidos (erratas y sinónimos)
//
//  Al crear un subtema en dim_tema, busca los existentes del mismo dominio que podrían ser el
//  mismo: igual con otra forma de escribirlo, con una errata, o con palabras equivalentes.
//  Son funciones puras (sin red) para poder probarlas con datos reales sin insertar nada.
//
//  Es una heurística, no entiende el significado: los sinónimos que detecta son los de
//  TEMAS_SINONIMOS (lista corta, editable) y el solapamiento de palabras del subtema.
// ============================================================

/** Grupos de palabras equivalentes (ya sin tildes y en singular). Ampliar según el vocabulario real. */
const TEMAS_SINONIMOS = [
    ['ubicacion', 'localizacion', 'direccion', 'lugar', 'sitio', 'sector', 'zona'],
    ['percepcion', 'opinion', 'imagen', 'valoracion', 'vision'],
    ['seguridad', 'proteccion', 'vigilancia'],
    ['precio', 'costo', 'tarifa', 'valor', 'gasto'],
    ['compra', 'adquisicion'],
    ['confianza', 'credibilidad', 'fiabilidad'],
    ['empleo', 'trabajo', 'laboral'],
    ['salud', 'bienestar'],
    ['conocimiento', 'awareness', 'reconocimiento', 'recordacion'],
    ['participacion', 'asistencia', 'involucramiento'],
    ['movilidad', 'transporte', 'desplazamiento'],
    ['educacion', 'formacion', 'estudio'],
    ['vivienda', 'hogar', 'residencia'],
];
const TEMAS_STOPWORDS = ['de','del','la','las','el','los','y','e','o','u','en','un','una','unos','unas','por','para','con','a','al','que','su','sus','se','sin','es','como'];

/** Minúsculas, sin tildes ni signos, espacios colapsados. */
function tsNormalizar(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Palabra → raíz simple (plural/terminación) y luego a su grupo de sinónimos (primer elemento). */
function tsCanonica(string $w): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        foreach (TEMAS_SINONIMOS as $g) foreach ($g as $p) $mapa[$p] = $g[0];
    }
    if (isset($mapa[$w])) return $mapa[$w];
    if (strlen($w) > 4 && str_ends_with($w, 'es')) $w2 = substr($w, 0, -2);
    elseif (strlen($w) > 3 && str_ends_with($w, 's'))  $w2 = substr($w, 0, -1);
    else $w2 = $w;
    return $mapa[$w] ?? $mapa[$w2] ?? $w2;
}

/** Conjunto de palabras significativas (sin stopwords, normalizadas, con sinónimos unificados). */
function tsTokens(string $s): array {
    $t = [];
    foreach (explode(' ', tsNormalizar($s)) as $w) {
        if ($w === '' || in_array($w, TEMAS_STOPWORDS, true)) continue;
        $t[tsCanonica($w)] = true;
    }
    return array_keys($t);
}

/**
 * Subtemas existentes del dominio que se parecen al nuevo.
 * @param array<int,array{id:int|string,dominio:string,tema:string,subtema:string,codigo:string}> $existentes
 * @return array<int,array{id:int,tema:string,subtema:string,codigo:string,motivo:string,puntaje:float}>
 *   motivo: 'igual' (misma escritura salvo tildes/mayúsculas), 'errata' (casi igual),
 *           'sinonimo' (mismas ideas con palabras equivalentes), 'parecido' (comparten palabras clave del subtema)
 */
function temasBuscarSubtemasParecidos(array $existentes, string $dominio, string $subtema): array {
    $nNuevo = tsNormalizar($subtema);
    $tNuevo = tsTokens($subtema);
    $res = [];
    foreach ($existentes as $e) {
        if ($e['dominio'] !== $dominio) continue;
        $nE = tsNormalizar($e['subtema']);
        $motivo = null; $puntaje = 0.0;
        if ($nE === $nNuevo) { $motivo = 'igual'; $puntaje = 1.0; }
        elseif (strlen($nNuevo) >= 6 && levenshtein($nE, $nNuevo) <= 2) { $motivo = 'errata'; $puntaje = 0.95; }
        else {
            $tE = tsTokens($e['subtema']);
            if (!$tNuevo || !$tE) continue;
            $comun = count(array_intersect($tNuevo, $tE));
            if ($comun === 0) continue;
            $union = count(array_unique(array_merge($tNuevo, $tE)));
            $jac = $comun / $union;
            $menor = min(count($tNuevo), count($tE));
            // Mismas ideas: todas las palabras del más corto aparecen en el otro (con 2+ palabras), o 50 % de solapamiento
            if ($jac >= 0.5 || ($menor >= 2 && $comun === $menor)) {
                $puntaje = round($jac, 2);
                // ¿Alguna de las palabras en común solo coincide por ser sinónimo (no la misma palabra escrita)?
                $porSinonimo = $nNuevo !== $nE && count(array_intersect(array_diff(explode(' ', $nNuevo), TEMAS_STOPWORDS), array_diff(explode(' ', $nE), TEMAS_STOPWORDS))) < $comun;
                $motivo = $porSinonimo ? 'sinonimo' : 'parecido';
            }
        }
        if ($motivo) $res[] = ['id' => (int)$e['id'], 'tema' => $e['tema'], 'subtema' => $e['subtema'], 'codigo' => $e['codigo'],
                               'motivo' => $motivo, 'puntaje' => $puntaje];
    }
    usort($res, fn($a, $b) => $b['puntaje'] <=> $a['puntaje']);
    return array_slice($res, 0, 5);
}
