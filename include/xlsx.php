<?php
require_once __DIR__ . '/zip.php';

/**
 * Spreadsheet reading/writing without PhpSpreadsheet or ext-zip.
 * Supports .xlsx/.xlsm (first or selected worksheet) and .csv.
 */

class XlsxReader {
    const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private $zip;
    private $sheetNames = [];
    private $sheetTargets = [];
    private $sharedStrings = [];
    private $dateStyles = [];
    private $date1904 = false;

    public function __construct($path) {
        $this->zip = new ZipReader($path);
        $this->loadWorkbook();
        $this->loadSharedStrings();
        $this->loadStyles();
    }

    public function sheetNames() {
        return $this->sheetNames;
    }

    private function xml($path) {
        $content = $this->zip->get($path);
        if ($content === null) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new RuntimeException('Could not parse the spreadsheet XML (' . $path . ').');
        }
        return $xml;
    }

    private function loadWorkbook() {
        $workbook = $this->xml('xl/workbook.xml');
        if ($workbook === null) {
            throw new RuntimeException('The workbook is missing xl/workbook.xml.');
        }

        $rels = [];
        $relXml = $this->xml('xl/_rels/workbook.xml.rels');
        if ($relXml !== null) {
            foreach ($relXml->xpath('//*[local-name()="Relationship"]') as $rel) {
                $rels[(string)$rel['Id']] = (string)$rel['Target'];
            }
        }

        $workbook->registerXPathNamespace('m', self::NS_MAIN);
        $props = $workbook->xpath('//m:workbookPr');
        if (!empty($props)) {
            $attrs = $props[0]->attributes();
            $value = strtolower((string)($attrs['date1904'] ?? ''));
            $this->date1904 = ($value === '1' || $value === 'true');
        }

        $sheets = $workbook->xpath('//m:sheets/m:sheet');
        if (empty($sheets)) {
            $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        }
        if (empty($sheets)) {
            throw new RuntimeException('The workbook does not contain any worksheets.');
        }

        foreach ($sheets as $sheet) {
            $name = (string)$sheet['name'];
            $rid = '';
            $relAttrs = $sheet->attributes(self::NS_REL);
            if (isset($relAttrs['id'])) {
                $rid = (string)$relAttrs['id'];
            }
            $target = $rels[$rid] ?? '';
            $target = ltrim($target, '/');
            if ($target === '') {
                $target = 'xl/worksheets/sheet' . (count($this->sheetNames) + 1) . '.xml';
            } elseif (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . $target;
            }
            $this->sheetNames[] = $name;
            $this->sheetTargets[$name] = $target;
        }
    }

    private function loadSharedStrings() {
        if (!$this->zip->has('xl/sharedStrings.xml')) {
            return;
        }
        $xml = $this->xml('xl/sharedStrings.xml');
        if ($xml === null) {
            return;
        }
        foreach ($xml->xpath('//*[local-name()="si"]') as $si) {
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                $text .= (string)$t;
            }
            $this->sharedStrings[] = $text;
        }
    }

    private function loadStyles() {
        if (!$this->zip->has('xl/styles.xml')) {
            return;
        }
        $xml = $this->xml('xl/styles.xml');
        if ($xml === null) {
            return;
        }

        $builtinDates = array_merge(range(14, 22), range(27, 36), [45, 46, 47], range(50, 58));
        $customFormats = [];
        foreach ($xml->xpath('//*[local-name()="numFmts"]/*[local-name()="numFmt"]') as $numFmt) {
            $customFormats[(int)$numFmt['numFmtId']] = (string)$numFmt['formatCode'];
        }

        foreach ($xml->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $index => $xf) {
            $numFmtId = (int)$xf['numFmtId'];
            $isDate = in_array($numFmtId, $builtinDates, true);
            if (!$isDate && isset($customFormats[$numFmtId])) {
                $code = preg_replace('/"[^"]*"/', '', $customFormats[$numFmtId]);
                $code = str_replace('\\', '', $code);
                if (preg_match('/[ydhs]/', $code)) {
                    $isDate = true;
                }
            }
            $this->dateStyles[$index] = $isDate;
        }
    }

    public function read($sheetName = null) {
        $target = null;
        if ($sheetName !== null && isset($this->sheetTargets[$sheetName])) {
            $target = $this->sheetTargets[$sheetName];
        } elseif (!empty($this->sheetTargets)) {
            $first = $this->sheetNames[0];
            $target = $this->sheetTargets[$first];
        }
        if ($target === null || !$this->zip->has($target)) {
            throw new RuntimeException('The requested worksheet could not be found.');
        }

        $xml = $this->xml($target);
        if ($xml === null) {
            throw new RuntimeException('The worksheet is empty or unreadable.');
        }
        $xml->registerXPathNamespace('m', self::NS_MAIN);

        $rowNodes = $xml->xpath('//m:sheetData/m:row');
        if (empty($rowNodes)) {
            $rowNodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
        }

        $matrix = [];
        foreach ($rowNodes as $rowNode) {
            $cells = $rowNode->xpath('*[local-name()="c"]');
            $line = [];
            foreach ($cells as $cell) {
                $ref = (string)$cell['r'];
                $letters = preg_replace('/[^A-Z]/i', '', $ref);
                $col = $letters !== '' ? excel_col_index($letters) : count($line);
                $line[$col] = $this->cellValue($cell);
            }
            if (!empty($line)) {
                ksort($line);
                $matrix[] = $line;
            }
        }

        return $matrix;
    }

    private function cellValue(SimpleXMLElement $cell) {
        $type = (string)$cell['t'];
        $style = (int)$cell['s'];

        if ($type === 'inlineStr') {
            $text = '';
            foreach ($cell->xpath('.//*[local-name()="t"]') as $t) {
                $text .= (string)$t;
            }
            return $text;
        }

        $valueNode = $cell->v;
        if ($valueNode === null) {
            $text = '';
            foreach ($cell->xpath('.//*[local-name()="t"]') as $t) {
                $text .= (string)$t;
            }
            return $text;
        }

        $value = (string)$valueNode;

        if ($type === 's') {
            $index = (int)$value;
            return $this->sharedStrings[$index] ?? '';
        }
        if ($type === 'b') {
            return $value === '1' ? '1' : '0';
        }
        if ($type === 'e') {
            return $value;
        }
        if ($type === 'str') {
            return $value;
        }

        if (is_numeric($value) && !empty($this->dateStyles[$style])) {
            return excel_serial_to_date((float)$value, $this->date1904);
        }

        return normalize_number_string($value);
    }
}

class CsvReader {
    private $path;

    public function __construct($path) {
        $this->path = $path;
    }

    public function sheetNames() {
        return ['CSV'];
    }

    public function read($sheetName = null) {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the CSV file.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('The CSV file is empty.');
        }
        $delimiter = ',';
        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
            '|' => substr_count($firstLine, '|'),
        ];
        arsort($counts);
        $delimiter = array_key_first($counts);
        rewind($handle);

        $matrix = [];
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || $cells === false) {
                continue;
            }
            foreach ($cells as $i => $cell) {
                $cell = (string)$cell;
                if ($i === 0 && $matrix === []) {
                    $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell);
                }
                if (!mb_check_encoding($cell, 'UTF-8')) {
                    $cell = mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
                }
                $cells[$i] = $cell;
            }
            $matrix[] = $cells;
        }
        fclose($handle);

        if (empty($matrix)) {
            throw new RuntimeException('The CSV file does not contain any rows.');
        }
        return $matrix;
    }
}

/**
 * Read a spreadsheet into normalized headers and associative rows.
 */
function mirrorcat_read_spreadsheet($path, $extension, $sheet = null, $maxRows = 0) {
    $extension = strtolower($extension);
    if (in_array($extension, ['csv', 'txt'], true)) {
        $reader = new CsvReader($path);
        $matrix = $reader->read();
    } elseif (in_array($extension, ['xlsx', 'xlsm'], true)) {
        $reader = new XlsxReader($path);
        $matrix = $reader->read($sheet);
    } else {
        throw new RuntimeException('Unsupported file type. Please upload an .xlsx, .xlsm or .csv file (older .xls files must be saved as .xlsx).');
    }

    $result = normalize_spreadsheet_matrix($matrix, $maxRows);
    $result['sheets'] = $reader->sheetNames();
    return $result;
}

/**
 * Turn a numeric matrix into a header list and associative rows.
 */
function normalize_spreadsheet_matrix(array $matrix, $maxRows = 0) {
    $matrix = array_values(array_filter($matrix, function ($row) {
        foreach ((array)$row as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }));

    if (empty($matrix)) {
        throw new RuntimeException('The spreadsheet does not contain any data.');
    }

    $headerRow = array_shift($matrix);
    $maxCol = 0;
    foreach (array_merge([$headerRow], $matrix) as $row) {
        if (!empty($row)) {
            $maxCol = max($maxCol, max(array_keys($row)) + 1);
        }
    }
    if ($maxCol === 0) {
        throw new RuntimeException('The spreadsheet does not contain any columns.');
    }

    $headers = [];
    $seen = [];
    for ($i = 0; $i < $maxCol; $i++) {
        $name = trim((string)($headerRow[$i] ?? ''));
        if ($name === '') {
            $name = 'Column ' . excel_col_letters($i + 1);
        }
        $base = $name;
        $suffix = 2;
        while (isset($seen[mb_strtolower($name)])) {
            $name = $base . ' (' . $suffix . ')';
            $suffix++;
        }
        $seen[mb_strtolower($name)] = true;
        $headers[] = $name;
    }

    $rows = [];
    foreach ($matrix as $line) {
        $assoc = [];
        $hasValue = false;
        foreach ($headers as $i => $header) {
            $value = trim((string)($line[$i] ?? ''));
            $assoc[$header] = $value;
            if ($value !== '') {
                $hasValue = true;
            }
        }
        if ($hasValue) {
            $rows[] = $assoc;
        }
        if ($maxRows > 0 && count($rows) >= $maxRows) {
            break;
        }
    }

    if (empty($rows)) {
        throw new RuntimeException('The spreadsheet has headers but no data rows.');
    }

    return [
        'headers' => $headers,
        'rows'    => $rows,
        'count'   => count($rows),
        'sheets'  => [],
    ];
}

function excel_col_index($letters) {
    $letters = strtoupper($letters);
    $index = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function excel_col_letters($index) {
    $index = (int)$index;
    $letters = '';
    while ($index > 0) {
        $index--;
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = intdiv($index, 26);
    }
    return $letters === '' ? 'A' : $letters;
}

function normalize_number_string($value) {
    if (!is_numeric($value)) {
        return $value;
    }
    if (preg_match('/^-?\d+$/', $value)) {
        return $value;
    }
    $float = (float)$value;
    if (floor($float) == $float && abs($float) < 1.0e15) {
        return sprintf('%.0f', $float);
    }
    $out = rtrim(rtrim(sprintf('%.10F', $float), '0'), '.');
    return $out === '' ? '0' : $out;
}

function excel_serial_to_date($serial, $date1904 = false) {
    $days = floor($serial);
    $fraction = $serial - $days;
    $base = $date1904 ? 24107 : 25569;
    $timestamp = (int)(($days - $base) * 86400) + (int)round($fraction * 86400);
    if (round($fraction * 86400) === 0) {
        return gmdate('Y-m-d', $timestamp);
    }
    return gmdate('Y-m-d H:i', $timestamp);
}

/* -------------------------------------------------------------------------
 * XLSX output
 * ---------------------------------------------------------------------- */

class XlsxWriter {
    public static function download($filename, array $headers, array $rows) {
        $bytes = self::build($headers, $rows);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        if (!preg_match('/\.xlsx$/i', $safe)) {
            $safe .= '.xlsx';
        }

        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        echo $bytes;
        exit;
    }

    public static function build(array $headers, array $rows) {
        $sheet = self::sheetXml($headers, $rows);
        $zip = new ZipWriter();

        $zip->add('[Content_Types].xml', self::contentTypes());
        $zip->add('_rels/.rels', self::rootRels());
        $zip->add('docProps/core.xml', self::coreProps());
        $zip->add('docProps/app.xml', self::appProps());
        $zip->add('xl/workbook.xml', self::workbook());
        $zip->add('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->add('xl/styles.xml', self::styles());
        $zip->add('xl/worksheets/sheet1.xml', $sheet);

        return $zip->build();
    }

    private static function contentTypes() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function coreProps() {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>mirrorCAT</dc:creator><cp:lastModifiedBy>mirrorCAT</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function appProps() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>mirrorCAT</Application></Properties>';
    }

    private static function workbook() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Results" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbookRels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="0"/>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D6EFD"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function sheetXml(array $headers, array $rows) {
        $headers = array_values($headers);
        $columns = max(1, count($headers));
        $widths = [];
        for ($i = 0; $i < $columns; $i++) {
            $widths[$i] = max(8, min(50, mb_strlen((string)$headers[$i]) + 2));
        }
        foreach ($rows as $row) {
            $row = array_values($row);
            for ($i = 0; $i < $columns; $i++) {
                $value = (string)($row[$i] ?? '');
                $widths[$i] = max($widths[$i], min(50, mb_strlen($value) + 2));
            }
        }

        $lastCol = excel_col_letters($columns);
        $lastRow = count($rows) + 1;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>';
        for ($i = 0; $i < $columns; $i++) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $widths[$i] . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';

        $xml .= '<row r="1">';
        for ($i = 0; $i < $columns; $i++) {
            $ref = excel_col_letters($i + 1) . '1';
            $xml .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . xml_escape((string)$headers[$i]) . '</t></is></c>';
        }
        $xml .= '</row>';

        foreach ($rows as $r => $row) {
            $row = array_values($row);
            $rowNumber = $r + 2;
            $xml .= '<row r="' . $rowNumber . '">';
            for ($i = 0; $i < $columns; $i++) {
                $value = (string)($row[$i] ?? '');
                if (trim($value) === '') {
                    continue;
                }
                $ref = excel_col_letters($i + 1) . $rowNumber;
                // Write every value as text so ISBNs, OCLC numbers and
                // leading zeros survive a round-trip through Excel.
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xml_escape($value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';
        if (count($rows) > 0) {
            $xml .= '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>';
        }
        $xml .= '</worksheet>';
        return $xml;
    }
}

function xml_escape($value) {
    $value = (string)$value;
    if (function_exists('iconv')) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($clean !== false) {
            $value = $clean;
        }
    }
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
