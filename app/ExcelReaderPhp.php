<?php
/**
 * ExcelReaderPhp — lectores de hojas de cálculo para el importador de leads.
 *
 * Dos lectores, misma API pública (getSheets()/readRows()/close()):
 *
 *   PhpXlsxReader        — .xlsx/.xlsm. Nativo (ZipArchive + SimpleXML), sin
 *                          dependencias externas, rápido. Sigue siendo el
 *                          camino por defecto para el formato más común.
 *   PhpCsvReader         — .csv. Nativo (detecta codificación y separador).
 *   PhpSpreadsheetReader  — .xls/.ods. Delega a PhpSpreadsheet (ya
 *                          instalado en vendor/, ver composer.json) — formatos
 *                          binarios/heterogéneos que no vale la pena
 *                          reimplementar a mano (BIFF de .xls en particular).
 *
 * API pública (ambas clases):
 *   $r = new PhpXlsxReader('/path/to/file.xlsx');   // o PhpSpreadsheetReader
 *   $r->getSheets()              → [['name'=>'Sheet1','rows'=>100,'cols'=>10,'headers'=>[...]], ...]
 *   $r->readHeader('Sheet1')     → ['id','created_time',...]  (primera fila con datos)
 *   $r->readRows('Sheet1', 21)   → [['col1','col2',...], ['v1','v2',...], ...]
 *   $r->close()
 */

class PhpXlsxReader
{
    private ZipArchive $zip;
    private array $sharedStrings = [];
    /** @var string[] sheetNames[0..n] */
    private array $sheetNames = [];
    /** @var string[] sheetFiles[0..n] — path inside ZIP like 'xl/worksheets/sheet1.xml' */
    private array $sheetFiles = [];
    /** @var array|null numFmtIds[i] — format id per cell style index (for date detection) */
    private ?array $numFmtIds = null;

    // Excel serial date epoch (Windows)
    private const EXCEL_EPOCH = 25569; // days since 1970-01-01

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive();
        $code = $this->zip->open($path);
        if ($code !== true) {
            throw new RuntimeException("No se pudo abrir el archivo Excel (código $code).");
        }

        $this->loadSharedStrings();
        $this->loadWorkbook();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function loadSharedStrings(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return; // archivo sin strings compartidos

        // Quitar declaración BOM si existe
        $xml = ltrim($xml, "\xEF\xBB\xBF");
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) return;

        foreach ($doc->si as $si) {
            // Texto simple: <si><t>valor</t></si>
            if (isset($si->t)) {
                // Preservar espacios (xml:space="preserve")
                $this->sharedStrings[] = (string)$si->t;
            } else {
                // Texto enriquecido: <si><r><t>...</t></r><r><t>...</t></r></si>
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
                $this->sharedStrings[] = $text;
            }
        }
    }

    private function loadWorkbook(): void
    {
        $xml = $this->zip->getFromName('xl/workbook.xml');
        if ($xml === false) throw new RuntimeException('xl/workbook.xml no encontrado en el archivo.');

        $xml = ltrim($xml, "\xEF\xBB\xBF");
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) throw new RuntimeException('xl/workbook.xml inválido.');

        // Namespace relationships
        $ns = $doc->getNamespaces(true);
        $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        $sheetRIds = [];
        foreach ($doc->sheets->sheet as $sheet) {
            $this->sheetNames[] = (string)$sheet['name'];
            $rAttrs = $sheet->attributes($rNs);
            $sheetRIds[] = (string)($rAttrs['id'] ?? '');
        }

        // Leer rels para mapear rId → archivo
        $relsXml = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml === false) throw new RuntimeException('xl/_rels/workbook.xml.rels no encontrado.');

        $relsXml = ltrim($relsXml, "\xEF\xBB\xBF");
        $relsDoc = simplexml_load_string($relsXml);
        $rIdToTarget = [];
        foreach ($relsDoc->Relationship as $rel) {
            $rIdToTarget[(string)$rel['Id']] = (string)$rel['Target'];
        }

        foreach ($sheetRIds as $i => $rId) {
            $target = $rIdToTarget[$rId] ?? '';
            // Target puede ser 'worksheets/sheet1.xml' o '../worksheets/sheet1.xml'
            if (str_starts_with($target, '/')) {
                $this->sheetFiles[$i] = ltrim($target, '/');
            } elseif (str_starts_with($target, '..')) {
                $this->sheetFiles[$i] = 'xl/' . substr($target, 3);
            } else {
                $this->sheetFiles[$i] = 'xl/' . $target;
            }
        }
    }

    /** Carga tabla de estilos para detectar celdas de fecha */
    private function loadStyles(): void
    {
        if ($this->numFmtIds !== null) return;
        $this->numFmtIds = [];

        $xml = $this->zip->getFromName('xl/styles.xml');
        if ($xml === false) return;
        $xml = ltrim($xml, "\xEF\xBB\xBF");
        $doc = simplexml_load_string($xml);
        if ($doc === false) return;

        foreach ($doc->cellXfs->xf as $xf) {
            $this->numFmtIds[] = (int)($xf['numFmtId'] ?? 0);
        }
    }

    private function isDateFmtId(int $fmtId): bool
    {
        // Built-in date format IDs in Excel: 14–22, 27–36, 45–47, 50–58
        if ($fmtId >= 14 && $fmtId <= 22) return true;
        if ($fmtId >= 27 && $fmtId <= 36) return true;
        if ($fmtId >= 45 && $fmtId <= 47) return true;
        if ($fmtId >= 50 && $fmtId <= 58) return true;
        return false;
    }

    private function excelSerialToDate(float $serial): string
    {
        // Excel bug: counts 1900 as leap year. Offset by 25569 days from Unix epoch.
        // But serial > 60 needs -1 correction for the fake leap day.
        $unix = ($serial > 60 ? $serial - 1 : $serial) * 86400;
        $unix -= self::EXCEL_EPOCH * 86400;
        $dt = new DateTime('@' . round($unix));
        // If there's a time component
        $h = (int)$dt->format('H');
        $i = (int)$dt->format('i');
        $s = (int)$dt->format('s');
        return ($h || $i || $s) ? $dt->format('Y-m-d H:i:s') : $dt->format('Y-m-d');
    }

    /** Resuelve el valor final de una celda <c> */
    private function cellValue(\SimpleXMLElement $cell, bool $checkDate = false): mixed
    {
        $t = (string)($cell['t'] ?? '');    // tipo: 's', 'str', 'inlineStr', 'b', 'e', '' (numeric)
        $v = isset($cell->v) ? (string)$cell->v : null;

        if ($v === null || $v === '') return null;

        // Shared string
        if ($t === 's') {
            return $this->sharedStrings[(int)$v] ?? null;
        }

        // Inline string
        if ($t === 'inlineStr') {
            return isset($cell->is->t) ? (string)$cell->is->t : $v;
        }

        // Formula result string
        if ($t === 'str') {
            return $v;
        }

        // Boolean
        if ($t === 'b') {
            return $v === '1';
        }

        // Error
        if ($t === 'e') {
            return null;
        }

        // Numeric (default) — puede ser entero, float o fecha
        if (!is_numeric($v)) return $v;

        $num = (float)$v;

        // Detectar celda de fecha por estilo
        if ($checkDate) {
            $styleIdx = isset($cell['s']) ? (int)$cell['s'] : -1;
            if ($styleIdx >= 0 && isset($this->numFmtIds[$styleIdx])) {
                $fmtId = $this->numFmtIds[$styleIdx];
                if ($this->isDateFmtId($fmtId) && $num > 1) {
                    return $this->excelSerialToDate($num);
                }
            }
        }

        // Entero sin decimales
        if ($num == (int)$num) {
            // IDs muy grandes → string para evitar pérdida de precisión
            if (abs($num) > 2147483647) return (string)(int)$num;
            return (int)$num;
        }

        return $num;
    }

    /** Convierte letra(s) de columna a índice 0-based. A→0, Z→25, AA→26 */
    private function colToIndex(string $col): int
    {
        $col = strtoupper($col);
        $idx = 0;
        for ($i = 0, $len = strlen($col); $i < $len; $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - 64);
        }
        return $idx - 1;
    }

    // ── API pública ───────────────────────────────────────────────────────────

    /**
     * Devuelve info de hojas: [['name'=>'Sheet1','rows'=>int,'cols'=>int], ...]
     */
    public function getSheets(): array
    {
        $this->loadStyles();
        $result = [];
        foreach ($this->sheetNames as $i => $name) {
            $filePath = $this->sheetFiles[$i] ?? '';
            $rows = 0; $cols = 0; $headers = [];
            if ($filePath && ($xml = $this->zip->getFromName($filePath)) !== false) {
                $xml = ltrim($xml, "ï»¿");
                $doc = simplexml_load_string($xml);
                if ($doc) {
                    // Contar filas no vacías (approx)
                    if (isset($doc->sheetData)) {
                        $rows = max(0, iterator_count($doc->sheetData->row) - 1);
                        // Encabezado = primera fila con datos (la misma que readRows() devuelve en [0])
                        foreach ($doc->sheetData->row as $row) {
                            $arr = $this->rowToArray($row);
                            if ($arr !== null) { $headers = $arr; break; }
                        }
                    }
                    // Columnas desde dimension
                    if (isset($doc->dimension['ref'])) {
                        $ref = (string)$doc->dimension['ref'];
                        if (preg_match('/([A-Z]+)\d+$/', $ref, $m)) {
                            $cols = $this->colToIndex($m[1]) + 1;
                        }
                    }
                    // Hay archivos (p. ej. guardados por algunas herramientas) sin <dimension>:
                    // contar las columnas del encabezado en vez de devolver 0.
                    if ($cols === 0) $cols = count($headers);
                }
            }
            $result[] = ['name' => $name, 'rows' => $rows, 'cols' => $cols, 'headers' => $headers];
        }
        return $result;
    }

    /**
     * Encabezado de una hoja (primera fila con datos) sin leer el resto: recorre el XML
     * con XMLReader y se detiene en la primera fila no vacía.
     */
    public function readHeader(string $sheetName): array
    {
        $idx = array_search($sheetName, $this->sheetNames);
        if ($idx === false) throw new RuntimeException("Hoja '$sheetName' no encontrada en el archivo.");
        $xml = $this->zip->getFromName($this->sheetFiles[$idx] ?? '');
        if ($xml === false) throw new RuntimeException("No se pudo leer la hoja '$sheetName'.");
        $this->loadStyles();
        $r = new XMLReader();
        $r->XML(ltrim($xml, "ï»¿"));
        $headers = [];
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'row') continue;
            $row = simplexml_load_string($r->readOuterXml());
            $arr = $row ? $this->rowToArray($row) : null;
            if ($arr !== null) { $headers = $arr; break; }
        }
        $r->close();
        return $headers;
    }

    /** Convierte un <row> en array denso; null si la fila no tiene datos. */
    private function rowToArray(\SimpleXMLElement $row): ?array
    {
        $cells = [];
        $maxColIdx = -1;
        foreach ($row->c as $cell) {
            // Extraer sólo letras de la referencia de celda (ej. 'AB12' → 'AB')
            $ref = (string)($cell['r'] ?? '');
            preg_match('/^([A-Z]+)/', $ref, $m);
            $colIdx = isset($m[1]) ? $this->colToIndex($m[1]) : count($cells);
            $cells[$colIdx] = $this->cellValue($cell, true);
            if ($colIdx > $maxColIdx) $maxColIdx = $colIdx;
        }
        if ($maxColIdx < 0) return null; // fila completamente vacía

        // Construir array denso (rellenando huecos con null)
        $rowArr = [];
        for ($i = 0; $i <= $maxColIdx; $i++) {
            $rowArr[] = $cells[$i] ?? null;
        }
        // Quitar nulos finales
        while (!empty($rowArr) && end($rowArr) === null) {
            array_pop($rowArr);
        }
        foreach ($rowArr as $v) {
            if ($v !== null && (string)$v !== '') return $rowArr;
        }
        return null; // sin datos
    }

    /**
     * Lee filas de una hoja. Devuelve array de arrays (primera fila = cabeceras).
     * $maxRows = 0 → todas las filas.
     */
    public function readRows(string $sheetName, int $maxRows = 0): array
    {
        $idx = array_search($sheetName, $this->sheetNames);
        if ($idx === false) {
            throw new RuntimeException("Hoja '$sheetName' no encontrada en el archivo.");
        }

        $filePath = $this->sheetFiles[$idx] ?? '';
        if (!$filePath) throw new RuntimeException("No se encontró el archivo de la hoja '$sheetName'.");

        $xml = $this->zip->getFromName($filePath);
        if ($xml === false) throw new RuntimeException("No se pudo leer xl/$filePath.");

        $this->loadStyles(); // lazy load estilos para detección de fechas

        $xml = ltrim($xml, "\xEF\xBB\xBF");
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) throw new RuntimeException("XML de hoja '$sheetName' inválido.");

        $rows  = [];
        $count = 0;

        foreach ($doc->sheetData->row as $row) {
            if ($maxRows > 0 && $count >= $maxRows) break;
            $rowArr = $this->rowToArray($row);
            if ($rowArr === null) continue; // fila vacía
            $rows[] = $rowArr;
            $count++;
        }

        return $rows;
    }

    public function close(): void
    {
        $this->zip->close();
    }
}

/**
 * Lector CSV/TSV nativo, sin dependencias. Detecta codificación (BOM UTF-8/UTF-16,
 * o Windows-1252 si no es UTF-8 válido) y separador (',' ';' tab) — los exports
 * de Meta Ads suelen venir en UTF-16 con tabs. Una sola hoja llamada "CSV".
 */
class PhpCsvReader
{
    /** @var string[][] */
    private array $rows = [];

    public function __construct(string $path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false) throw new RuntimeException('No se pudo leer el archivo CSV.');

        if (str_starts_with($raw, "\xFF\xFE"))     $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        elseif (str_starts_with($raw, "\xFE\xFF")) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        else {
            $raw = ltrim($raw, "\xEF\xBB\xBF");
            if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($raw, "\r\n") ?: '';
        $delim = ',';
        $best  = -1;
        foreach ([',', ';', "\t"] as $d) {
            $n = substr_count($firstLine, $d);
            if ($n > $best) { $best = $n; $delim = $d; }
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if (!array_filter($r, fn($v) => $v !== null && $v !== '')) continue;
            $this->rows[] = array_map(fn($v) => (string)$v, $r);
        }
        fclose($fh);
    }

    public function getSheets(): array
    {
        return [[
            'name' => 'CSV',
            // Igual que PhpSpreadsheetReader: filas de datos, sin contar el encabezado
            'rows' => max(0, count($this->rows) - 1),
            'cols' => $this->rows ? max(array_map('count', $this->rows)) : 0,
            'headers' => $this->rows[0] ?? [],
        ]];
    }

    public function readHeader(string $sheetName): array
    {
        return $this->rows[0] ?? [];
    }

    public function readRows(string $sheetName, int $maxRows = 0): array
    {
        return $maxRows > 0 ? array_slice($this->rows, 0, $maxRows) : $this->rows;
    }

    public function close(): void
    {
        $this->rows = [];
    }
}

/**
 * Lector de respaldo para .xls (binario legado, formato BIFF) / .ods,
 * vía PhpSpreadsheet. Mismo shape de retorno que PhpXlsxReader para que
 * xlsxGetSheets()/xlsxReadRows() en leads_importer.php no necesiten saber
 * cuál de los dos lectores están usando.
 */
class PhpSpreadsheetReader
{
    private \PhpOffice\PhpSpreadsheet\Spreadsheet $book;

    public function __construct(string $path)
    {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new RuntimeException(
                'Este formato requiere PhpSpreadsheet (carpeta vendor/ no encontrada). ' .
                'Ejecuta "composer install" en el proyecto, o convierte el archivo a .xlsx.'
            );
        }
        require_once $autoload;

        // Detección por CONTENIDO, no por extensión — plataformas como Meta Ads
        // exportan archivos con extensión ".xls" que en realidad son XML
        // (SpreadsheetML de Excel 2003) o incluso tablas HTML, no binario OLE2
        // real. Confiar en la extensión rompe con esos casos; IOFactory
        // sniffea las primeras líneas del archivo y elige el reader correcto.
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            throw new RuntimeException('No se pudo identificar el formato del archivo: ' . $e->getMessage());
        }

        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
            $reader->setDelimiter($this->detectCsvDelimiter($path));
            // Encoding: el reader ya auto-detecta BOM/UTF-8 por defecto (Csv::openFileOrMemory),
            // no hace falta forzarlo.
        }
        $reader->setReadDataOnly(true);
        $this->book = $reader->load($path);
    }

    /** Excel/exportadores de LATAM suelen usar ';' cuando la coma es separador decimal */
    private function detectCsvDelimiter(string $path): string
    {
        $fh = @fopen($path, 'r');
        $line = $fh ? (string)fgets($fh) : '';
        if ($fh) fclose($fh);
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    public function getSheets(): array
    {
        $result = [];
        foreach ($this->book->getAllSheets() as $sheet) {
            $highestCol = $sheet->getHighestDataColumn();
            $result[] = [
                'name' => $sheet->getTitle(),
                'rows' => max(0, $sheet->getHighestDataRow() - 1),
                'cols' => $highestCol
                    ? \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol)
                    : 0,
                'headers' => $this->readRows($sheet->getTitle(), 1)[0] ?? [],
            ];
        }
        return $result;
    }

    public function readHeader(string $sheetName): array
    {
        return $this->readRows($sheetName, 1)[0] ?? [];
    }

    public function readRows(string $sheetName, int $maxRows = 0): array
    {
        $sheet = $this->book->getSheetByName($sheetName);
        if (!$sheet) throw new RuntimeException("Hoja '$sheetName' no encontrada en el archivo.");

        $rows = [];
        $count = 0;

        foreach ($sheet->getRowIterator() as $row) {
            if ($maxRows > 0 && $count >= $maxRows) break;

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $rowArr = [];
            foreach ($cellIterator as $cell) {
                $rowArr[] = $this->cellValue($cell);
            }

            // Quitar nulos finales
            while (!empty($rowArr) && end($rowArr) === null) {
                array_pop($rowArr);
            }

            $hasData = false;
            foreach ($rowArr as $v) {
                if ($v !== null && (string)$v !== '') { $hasData = true; break; }
            }
            if (!$hasData) continue;

            $rows[] = $rowArr;
            $count++;
        }

        return $rows;
    }

    /** Normaliza fechas al mismo formato que PhpXlsxReader (Y-m-d [H:i:s]) */
    private function cellValue(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): mixed
    {
        $val = $cell->getCalculatedValue();
        if ($val === null || $val === '') return null;

        if (is_numeric($val)) {
            $fmt = $cell->getStyle()->getNumberFormat()->getFormatCode();
            if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTimeFormatCode($fmt)) {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
                $h = (int)$dt->format('H'); $i = (int)$dt->format('i'); $s = (int)$dt->format('s');
                return ($h || $i || $s) ? $dt->format('Y-m-d H:i:s') : $dt->format('Y-m-d');
            }
            // IDs de Meta Ads (lead id, ad_id, adset_id, campaign_id) llegan como
            // string exacto de hasta 18-19 dígitos. Un roundtrip por float (double,
            // ~15-17 dígitos significativos) los redondea de forma silenciosa e
            // irreversible — preservar el string tal cual llegó en ese caso, sin
            // convertir. Solo se float-castea lo que cabe sin pérdida.
            if (is_string($val) && preg_match('/^-?\d+$/', $val) && strlen(ltrim($val, '-')) > 15) {
                return $val;
            }
            $num = (float)$val;
            if ($num == (int)$num && abs($num) <= 2147483647) return (int)$num;
            return $num;
        }

        return $val;
    }

    public function close(): void
    {
        unset($this->book);
    }
}

// ── Validación de estructura: ¿la hoja es una exportación de leads de Meta Ads? ──

/**
 * Columnas fijas de una exportación de leads de Meta Ads (Lead Ads → «Descargar leads»).
 * Una exportación trae, en la fila 1 y en este orden:
 *   id, created_time, ad_id, ad_name, adset_id, adset_name, campaign_id, campaign_name,
 *   form_id, form_name, is_organic, platform, <preguntas del formulario…>, [lead_status]
 * Se comparan normalizadas con _normMetaCol() («Created Time», «created_time» y «created time» valen igual).
 */
const META_COLS_OBLIGATORIAS = [
    'created time', 'ad id', 'ad name', 'adset id', 'adset name',
    'campaign id', 'campaign name', 'form id', 'form name',
];
const META_COLS_OPCIONALES = ['is organic', 'platform'];

/**
 * Valida que una hoja sea una exportación de leads de Meta Ads a partir de su encabezado
 * (primera fila con datos). Regla (ver docs/importador_validacion_hojas.md):
 *   1. El encabezado debe contener las 9 columnas fijas obligatorias (META_COLS_OBLIGATORIAS).
 *   2. Debe haber columna de ID del lead: un encabezado «id»/«lead id», o, si no lo hay, la
 *      columna 1 debe ser una columna propia (cargas antiguas con el id renombrado o sin
 *      título). Si la columna 1 es otra columna fija de Meta (p. ej. created_time) no hay id
 *      y se rechaza: se usaría la fecha como ID y los leads se fusionarían.
 * Devuelve ['valid'=>bool, 'reason'=>?string, 'missing'=>[...], 'warning'=>?string].
 */
function validarHojaMeta(array $headers): array {
    $norm = array_map(fn($h) => _normMetaCol($h), $headers);
    $present = array_flip(array_filter($norm, fn($n) => $n !== ''));
    if (!$present) {
        return ['valid' => false, 'missing' => META_COLS_OBLIGATORIAS,
                'reason' => 'Hoja vacía o sin encabezado.', 'warning' => null];
    }
    $missing = array_values(array_filter(META_COLS_OBLIGATORIAS, fn($c) => !isset($present[$c])));
    if ($missing) {
        $nombres = array_map(fn($c) => str_replace(' ', '_', $c), $missing);
        $reason = count($missing) === count(META_COLS_OBLIGATORIAS)
            ? 'No es una exportación de leads de Meta Ads: no tiene ninguna columna fija (created_time, ad_id, adset_name…).'
            : 'No tiene las columnas fijas de Meta Ads: faltan ' . implode(', ', $nombres) . '.';
        return ['valid' => false, 'missing' => $nombres, 'reason' => $reason, 'warning' => null];
    }
    $warning = null;
    if (!isset($present['id']) && !isset($present['lead id'])) {
        $col0 = $norm[0] ?? '';
        $fijas = array_merge(META_COLS_OBLIGATORIAS, META_COLS_OPCIONALES);
        if (in_array($col0, $fijas, true)) {
            return ['valid' => false, 'missing' => ['id'],
                    'reason' => 'No tiene la columna id del lead (la columna 1 es ' . str_replace(' ', '_', $col0) . ').',
                    'warning' => null];
        }
        $titulo = trim((string)($headers[0] ?? ''));
        $warning = 'Sin columna «id»: se usará la columna 1' . ($titulo !== '' ? " («{$titulo}»)" : ' (sin título)') . ' como ID del lead.';
    }
    return ['valid' => true, 'missing' => [], 'reason' => null, 'warning' => $warning];
}

/** Igual que normalizeCol() de leads_importer.php: minúsculas, sin tildes, separadores → espacio. */
function _normMetaCol($col): string {
    if ($col === null || trim((string)$col) === '') return '';
    $col = mb_strtolower(trim((string)$col), 'UTF-8');
    $col = strtr($col, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $col = preg_replace('/[^a-z0-9 ]/u', ' ', $col);
    return trim(preg_replace('/\s+/', ' ', $col));
}

/**
 * Añade a cada hoja de getSheets() su validez como exportación de Meta Ads
 * (valid, reason, missing, warning) y quita 'headers', que no viaja al navegador.
 */
function anotarValidezHojas(array $sheets): array {
    foreach ($sheets as &$sh) {
        $v = validarHojaMeta($sh['headers'] ?? []);
        unset($sh['headers']);
        $sh += $v;
    }
    unset($sh);
    return $sheets;
}
