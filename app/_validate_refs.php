<?php
/**
 * MEDFUT · Validación de integridad referencial completa
 *
 *  Cadena esperada:
 *    import_log (PK id)
 *      → import_log_leads.import_id          CASCADE DELETE ✓
 *      → import_electoral.import_id           CASCADE DELETE ✓
 *    import_log_leads (PK id VARCHAR 64)
 *      → import_log_leads_respuestas.lead_id  CASCADE DELETE ✓
 *
 * Ejecutar en: http://localhost/medfut/_validate_refs.php
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$db  = getDB();
$out = ['issues' => [], 'fixes_applied' => []];

// ── Helper: obtener FKs de una tabla ────────────────────────────────────────
function getFKs(PDO $db, string $table): array {
    $stmt = $db->prepare("
        SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
               kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
               rc.DELETE_RULE, rc.UPDATE_RULE
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
        WHERE kcu.TABLE_SCHEMA = DATABASE()
          AND kcu.TABLE_NAME = ?
          AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $stmt->execute([$table]);
    return $stmt->fetchAll();
}

// ── Helper: columna de una tabla ─────────────────────────────────────────────
function getColType(PDO $db, string $table, string $col): ?string {
    $r = $db->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $r->execute([$table, $col]);
    return $r->fetchColumn() ?: null;
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. Esquema de tablas involucradas
// ══════════════════════════════════════════════════════════════════════════════
$tables = ['import_log', 'import_log_leads', 'import_log_leads_respuestas', 'import_electoral'];
foreach ($tables as $tbl) {
    try {
        $out['schema'][$tbl] = $db->query("DESCRIBE `$tbl`")->fetchAll();
    } catch (\Exception $e) {
        $out['schema'][$tbl] = 'ERROR: ' . $e->getMessage();
        $out['issues'][] = "Tabla $tbl no existe o no es accesible";
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. Validar FKs y DELETE_RULE para cada enlace de la cadena
// ══════════════════════════════════════════════════════════════════════════════
$chain = [
    // tabla_hijo => [col_hijo, tabla_padre, col_padre, CASCADE requerido]
    'import_log_leads'              => ['import_id', 'import_log',       'id',       true],
    'import_log_leads_respuestas'   => ['lead_id',   'import_log_leads', 'id',       true],
    'import_electoral'              => ['import_id', 'import_log',       'id',       true],
];

foreach ($chain as $childTbl => [$childCol, $parentTbl, $parentCol, $needsCascade]) {
    $fks = getFKs($db, $childTbl);

    $found = null;
    foreach ($fks as $fk) {
        if ($fk['COLUMN_NAME'] === $childCol && $fk['REFERENCED_TABLE_NAME'] === $parentTbl) {
            $found = $fk;
            break;
        }
    }

    $link = "$childTbl.$childCol → $parentTbl.$parentCol";

    if (!$found) {
        $out['fk_chain'][$link] = ['status' => '❌ FK NO EXISTE'];
        $out['issues'][] = "FK faltante: $link";

        // Auto-fix: agregar la FK con CASCADE
        try {
            $cname = 'fk_' . strtolower(str_replace(['import_log_', 'import_'], ['', 'il_'], $childTbl))
                   . '_' . strtolower(str_replace(['import_log_', 'import_'], ['', 'il_'], $parentTbl));
            $cascade = $needsCascade ? 'ON DELETE CASCADE ON UPDATE CASCADE' : '';
            $db->exec("ALTER TABLE `$childTbl`
                       ADD CONSTRAINT `$cname`
                       FOREIGN KEY (`$childCol`) REFERENCES `$parentTbl`(`$parentCol`)
                       $cascade");
            $out['fk_chain'][$link] = ['status' => '✅ FK CREADA AUTO'];
            $out['fixes_applied'][] = "Creada FK $cname en $childTbl";
        } catch (\Exception $e) {
            $out['fk_chain'][$link]['fix_error'] = $e->getMessage();
        }

    } else {
        $deleteRule = $found['DELETE_RULE'];
        $ok = !$needsCascade || $deleteRule === 'CASCADE';
        $out['fk_chain'][$link] = [
            'status'       => $ok ? '✅ OK' : '⚠️ DELETE_RULE incorrecto',
            'constraint'   => $found['CONSTRAINT_NAME'],
            'delete_rule'  => $deleteRule,
            'update_rule'  => $found['UPDATE_RULE'],
        ];
        if (!$ok) {
            $out['issues'][] = "FK $link existe pero DELETE_RULE=$deleteRule (necesita CASCADE)";

            // Auto-fix: drop + recrear con CASCADE
            try {
                $db->exec("ALTER TABLE `$childTbl` DROP FOREIGN KEY `{$found['CONSTRAINT_NAME']}`");
                $db->exec("ALTER TABLE `$childTbl`
                           ADD CONSTRAINT `{$found['CONSTRAINT_NAME']}`
                           FOREIGN KEY (`$childCol`) REFERENCES `$parentTbl`(`$parentCol`)
                           ON DELETE CASCADE ON UPDATE CASCADE");
                $out['fk_chain'][$link]['status']    = '✅ FK CORREGIDA A CASCADE';
                $out['fixes_applied'][] = "Corregida a CASCADE: {$found['CONSTRAINT_NAME']}";
            } catch (\Exception $e) {
                $out['fk_chain'][$link]['fix_error'] = $e->getMessage();
            }
        }
    }

    // Validar tipos compatibles entre FK
    $parentType = getColType($db, $parentTbl, $parentCol);
    $childType  = getColType($db, $childTbl,  $childCol);
    $compatible = $parentType && $childType && (strtolower($parentType) === strtolower($childType));
    $out['fk_chain'][$link]['type_check'] = [
        'parent' => "$parentTbl.$parentCol = $parentType",
        'child'  => "$childTbl.$childCol = $childType",
        'ok'     => $compatible ? '✅' : '❌ INCOMPATIBLES',
    ];
    if (!$compatible) {
        $out['issues'][] = "Tipos incompatibles en FK $link: $parentType vs $childType";
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. Chequeo de huérfanos en cada tabla hija
// ══════════════════════════════════════════════════════════════════════════════
$orphanChecks = [
    'import_log_leads.import_id → import_log' => [
        "SELECT COUNT(*) FROM import_log_leads l
         WHERE NOT EXISTS (SELECT 1 FROM import_log p WHERE p.id = l.import_id)"
    ],
    'import_log_leads_respuestas.lead_id → import_log_leads' => [
        "SELECT COUNT(*) FROM import_log_leads_respuestas r
         WHERE NOT EXISTS (SELECT 1 FROM import_log_leads l WHERE l.id = r.lead_id)"
    ],
    'import_electoral.import_id → import_log' => [
        "SELECT COUNT(*) FROM import_electoral e
         WHERE NOT EXISTS (SELECT 1 FROM import_log p WHERE p.id = e.import_id)"
    ],
];

foreach ($orphanChecks as $label => [$sql]) {
    try {
        $cnt = (int)$db->query($sql[0] ?? $sql)->fetchColumn();
        $out['orphans'][$label] = $cnt === 0 ? '✅ 0 huérfanos' : "❌ $cnt huérfanos";
        if ($cnt > 0) $out['issues'][] = "$cnt registros huérfanos en $label";
    } catch (\Exception $e) {
        $out['orphans'][$label] = 'ERROR: ' . $e->getMessage();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. Conteos generales
// ══════════════════════════════════════════════════════════════════════════════
foreach ($tables as $tbl) {
    try {
        $out['counts'][$tbl] = (int)$db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
    } catch (\Exception $e) {
        $out['counts'][$tbl] = 'ERROR';
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 5. Resultado
// ══════════════════════════════════════════════════════════════════════════════
$out['status'] = empty($out['issues'])
    ? '✅ Integridad referencial completa — todo OK'
    : '⚠️ ' . count($out['issues']) . ' problema(s) detectado(s)';

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
