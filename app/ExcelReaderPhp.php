<?php
/**
 * ExcelReaderPhp — lectores de hojas de cálculo para el importador de leads.
 *
 * Dos lectores, misma API pública (getSheets()/readRows()/close()):
 *
 *   PhpXlsxReader        — .xlsx/.xlsm. Nativo (ZipArchive + SimpleXML), sin
 *                          dependencias externas, rápido. Sigue siendo el
 *                          camino por defecto para el formato más común.
 *   PhpSpreadsheetReader  — .xls/.csv/.ods. Delega a PhpSpreadsheet (ya
 *                          instalado en vendor/, ver composer.json) — formatos
 *                          binarios/heterogéneos que no vale la pena
 *                          reimplementar a mano (BIFF de .xls en particular).
 *
 * API pública (ambas clases):
 *   $r = new PhpXlsxReader('/path/to/file.xlsx');   // o PhpSpreadsheetReader
 *   $r->getSheets()              → [['name'=>'Sheet1','rows'=>100,'cols'=>10], ...]
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
        $result = [];
        foreach ($this->sheetNames as $i => $name) {
            $filePath = $this->sheetFiles[$i] ?? '';
            $rows = 0; $cols = 0;
            if ($filePath && ($xml = $this->zip->getFromName($filePath)) !== false) {
                $xml = ltrim($xml, "\xEF\xBB\xBF");
                $doc = simplexml_load_string($xml);
                if ($doc) {
                    // Contar filas no vacías (approx)
                    if (isset($doc->sheetData)) {
                        $rows = max(0, iterator_count($doc->sheetData->row) - 1);
                    }
                    // Columnas desde dimension
                    if (isset($doc->dimension['ref'])) {
                        $ref = (string)$doc->dimension['ref'];
                        if (preg_match('/([A-Z]+)\d+$/', $ref, $m)) {
                            $cols = $this->colToIndex($m[1]) + 1;
                        }
                    }
                }
            }
            $result[] = ['name' => $name, 'rows' => $rows, 'cols' => $cols];
        }
        return $result;
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

            if ($maxColIdx < 0) continue; // fila completamente vacía

            // Construir array denso (rellenando huecos con null)
            $rowArr = [];
            for ($i = 0; $i <= $maxColIdx; $i++) {
                $rowArr[] = $cells[$i] ?? null;
            }

            // Quitar nulos finales
            while (!empty($rowArr) && end($rowArr) === null) {
                array_pop($rowArr);
            }

            // Omitir si queda completamente vacía
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

    public function close(): void
    {
        $this->zip->close();
    }
}

/**
 * Lector de respaldo para .xls (binario legado, formato BIFF) / .csv / .ods,
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
            ];
        }
        return $result;
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
