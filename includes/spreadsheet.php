<?php
/**
 * Helpers tableur minimalistes (sans dépendance externe).
 *  - buildXlsx()      : génère un .xlsx (Open XML) à partir d'un tableau 2D.
 *  - readSpreadsheet(): lit un fichier .xlsx, SpreadsheetML (.xls XML) ou .csv → tableau 2D de chaînes.
 */

function _xmlEsc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Index colonne 0-based → lettre Excel (0→A, 26→AA). */
function _colLetter(int $n): string {
    $s = '';
    $n++;
    while ($n > 0) {
        $r = ($n - 1) % 26;
        $s = chr(65 + $r) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

/**
 * Construit un .xlsx (binaire) depuis $rows (array de lignes, chaque ligne = array de valeurs scalaires).
 * Les valeurs numériques deviennent des nombres, le reste des chaînes (inlineStr).
 */
function buildXlsx(array $rows, string $sheetName = 'Feuille1'): string {
    $sheetData = '';
    $rowNum = 0;
    foreach ($rows as $row) {
        $rowNum++;
        $cells = '';
        $col = 0;
        foreach ($row as $val) {
            $ref = _colLetter($col) . $rowNum;
            $col++;
            if ($val === null || $val === '') continue;
            if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val) && !preg_match('/^0\d/', $val))) {
                $cells .= "<c r=\"$ref\"><v>" . _xmlEsc((string)$val) . "</v></c>";
            } else {
                $cells .= "<c r=\"$ref\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . _xmlEsc((string)$val) . "</t></is></c>";
            }
        }
        $sheetData .= "<row r=\"$rowNum\">$cells</row>";
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . "<sheetData>$sheetData</sheetData></worksheet>";

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . _xmlEsc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Lit un fichier tableur (auto-détection xlsx / SpreadsheetML / csv) → array de lignes (array de chaînes). */
function readSpreadsheet(string $path): array {
    $sig = (string)file_get_contents($path, false, null, 0, 8);
    if (strncmp($sig, "PK\x03\x04", 4) === 0) return _readXlsx($path);

    $content = (string)file_get_contents($path);
    // BOM UTF-8
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) $content = substr($content, 3);
    $trim = ltrim($content);
    if (stripos($trim, '<?xml') === 0 || stripos($trim, '<workbook') !== false || stripos($trim, '<ss:workbook') !== false) {
        return _readSpreadsheetML($content);
    }
    return _readCsv($content);
}

/** Lit un .xlsx. */
function _readXlsx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    // Shared strings
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $dom = new DOMDocument();
        @$dom->loadXML($ssXml);
        foreach ($dom->getElementsByTagName('si') as $si) {
            // concatène tous les <t> du <si>
            $txt = '';
            foreach ($si->getElementsByTagName('t') as $t) $txt .= $t->textContent;
            $shared[] = $txt;
        }
    }

    // Première feuille
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/') === 0 && substr($name, -4) === '.xml') {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();
    if ($sheetXml === false || $sheetXml === null) return [];

    $dom = new DOMDocument();
    @$dom->loadXML($sheetXml);
    $rows = [];
    foreach ($dom->getElementsByTagName('row') as $rowEl) {
        $cells = [];
        foreach ($rowEl->getElementsByTagName('c') as $c) {
            $ref  = $c->getAttribute('r');
            $type = $c->getAttribute('t');
            $colIdx = _refToCol($ref);
            $val = '';
            if ($type === 'inlineStr') {
                foreach ($c->getElementsByTagName('t') as $t) $val .= $t->textContent;
            } else {
                $vEl = $c->getElementsByTagName('v')->item(0);
                $raw = $vEl ? $vEl->textContent : '';
                if ($type === 's') $val = $shared[(int)$raw] ?? '';
                else               $val = $raw;
            }
            $cells[$colIdx] = $val;
        }
        $rows[] = _normalizeRow($cells);
    }
    return $rows;
}

/** Lit un SpreadsheetML (.xls XML). */
function _readSpreadsheetML(string $content): array {
    $dom = new DOMDocument();
    @$dom->loadXML($content);
    $rows = [];
    foreach ($dom->getElementsByTagName('Row') as $rowEl) {
        $cells = [];
        $col = 0;
        foreach ($rowEl->getElementsByTagName('Cell') as $cellEl) {
            $idx = $cellEl->getAttribute('ss:Index');
            if ($idx === '') $idx = $cellEl->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'Index');
            if ($idx !== '') $col = (int)$idx - 1;
            $dataEl = $cellEl->getElementsByTagName('Data')->item(0);
            $cells[$col] = $dataEl ? $dataEl->textContent : '';
            $col++;
        }
        $rows[] = _normalizeRow($cells);
    }
    return $rows;
}

/** Lit un CSV (détection séparateur , ou ;). */
function _readCsv(string $content): array {
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $content);
    $delim = (substr_count($content, ';') > substr_count($content, ',')) ? ';' : ',';
    $rows = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $rows[] = array_map(fn($v) => trim($v), str_getcsv($line, $delim));
    }
    return $rows;
}

/** Référence cellule (ex "B3") → index colonne 0-based. */
function _refToCol(string $ref): int {
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) return 0;
    $letters = $m[1];
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/** Comble les trous d'un tableau indexé par colonne → tableau séquentiel 0..max. */
function _normalizeRow(array $cells): array {
    if (empty($cells)) return [];
    $max = max(array_keys($cells));
    $out = [];
    for ($i = 0; $i <= $max; $i++) $out[$i] = $cells[$i] ?? '';
    return $out;
}
