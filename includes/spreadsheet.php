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

/** Charge les chaînes partagées (xl/sharedStrings.xml) d'un classeur ouvert. */
function _loadSharedStrings(ZipArchive $zip): array {
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
    return $shared;
}

/** Convertit le XML d'une feuille (<sheetData>) en tableau 2D de chaînes. */
function _parseSheetRows(string $sheetXml, array $shared): array {
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

/** Lit un .xlsx (première feuille uniquement). */
function _readXlsx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $shared = _loadSharedStrings($zip);

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

    return _parseSheetRows($sheetXml, $shared);
}

/**
 * Lit toutes les feuilles d'un .xlsx → ['Nom de feuille' => tableau 2D de chaînes, ...]
 * (ordre = ordre des feuilles dans le classeur)
 */
function readXlsxSheets(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $shared = _loadSharedStrings($zip);

    // Nom de feuille → r:id (ordre du classeur)
    $sheetIds = []; // rId => name, dans l'ordre
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if ($wbXml !== false) {
        $dom = new DOMDocument();
        @$dom->loadXML($wbXml);
        foreach ($dom->getElementsByTagName('sheet') as $sh) {
            $rid = $sh->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $sheetIds[$rid] = $sh->getAttribute('name');
        }
    }

    // r:id → chemin du fichier worksheet
    $targets = [];
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsXml !== false) {
        $dom = new DOMDocument();
        @$dom->loadXML($relsXml);
        foreach ($dom->getElementsByTagName('Relationship') as $rel) {
            if (strpos($rel->getAttribute('Type'), '/worksheet') !== false) {
                $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }
    }

    $result = [];
    foreach ($sheetIds as $rid => $name) {
        $target = $targets[$rid] ?? null;
        if (!$target) continue;
        $target = ltrim($target, '/');
        $path2 = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
        $sheetXml = $zip->getFromName($path2);
        if ($sheetXml === false) continue;
        $result[$name] = _parseSheetRows($sheetXml, $shared);
    }
    $zip->close();
    return $result;
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

/**
 * Construit un .xlsx multi-feuilles avec mise en forme minimale.
 * $sheets : [ ['name' => 'Feuille', 'rows' => [[...], ...], 'styles' => [rowIdx => styleId], 'widths' => [colIdx => largeur]], ... ]
 * Les cellules peuvent être des scalaires ou ['v' => valeur, 's' => styleId] (style par cellule).
 * Styles : 0 normal · 1 titre · 2 en-tête · 3 nombre 0.00 · 4 nombre 0.00 gras vert · 5 nombre 0.00 gras rouge · 6 italique gris · 7 gras
 */
function buildXlsxMulti(array $sheets): string {
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>'
        . '<fonts count="6">'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="14"/><color rgb="FF1E3A5F"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><color rgb="FF155724"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><color rgb="FF721C24"/><name val="Calibri"/></font>'
        .   '<font><i/><sz val="10"/><color rgb="FF666666"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill></fills>'
        . '<borders count="2"><border/><border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
        .   '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="8">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"/>'
        .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        .   '<xf numFmtId="164" fontId="3" fillId="0" borderId="1" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        .   '<xf numFmtId="164" fontId="4" fillId="0" borderId="1" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        .   '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1"/>'
        . '</cellXfs></styleSheet>';

    $ctSheets = ''; $wbSheets = ''; $wbRels = ''; $files = [];
    $usedNames = [];
    foreach (array_values($sheets) as $i => $sh) {
        $n = $i + 1;
        // Nom de feuille Excel : 31 car. max, sans []:*?/\ et unique
        $name = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', '-', (string)($sh['name'] ?? "Feuille$n")), 0, 31);
        $base = $name; $k = 2;
        while (isset($usedNames[mb_strtolower($name)])) { $name = mb_substr($base, 0, 27) . " ($k)"; $k++; }
        $usedNames[mb_strtolower($name)] = true;

        $cols = '';
        foreach (($sh['widths'] ?? []) as $c => $w) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        }
        $sheetData = '';
        foreach (array_values($sh['rows'] ?? []) as $r => $row) {
            $rowNum = $r + 1;
            $rowStyle = (int)($sh['styles'][$r] ?? 0);
            $cells = '';
            foreach (array_values($row) as $c => $cell) {
                $val = is_array($cell) ? ($cell['v'] ?? null) : $cell;
                $st  = is_array($cell) && isset($cell['s']) ? (int)$cell['s'] : $rowStyle;
                $ref = _colLetter($c) . $rowNum;
                $sAttr = $st ? " s=\"$st\"" : '';
                if ($val === null || $val === '') { if ($st) $cells .= "<c r=\"$ref\"$sAttr/>"; continue; }
                if (is_int($val) || is_float($val)) {
                    $cells .= "<c r=\"$ref\"$sAttr><v>" . _xmlEsc((string)$val) . "</v></c>";
                } else {
                    $cells .= "<c r=\"$ref\"$sAttr t=\"inlineStr\"><is><t xml:space=\"preserve\">" . _xmlEsc((string)$val) . "</t></is></c>";
                }
            }
            $sheetData .= "<row r=\"$rowNum\">$cells</row>";
        }
        $merges = '';
        if (!empty($sh['merges'])) {
            $merges = '<mergeCells count="' . count($sh['merges']) . '">';
            foreach ($sh['merges'] as $m) $merges .= '<mergeCell ref="' . _xmlEsc($m) . '"/>';
            $merges .= '</mergeCells>';
        }
        $freeze = isset($sh['freeze_row'])
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . (int)$sh['freeze_row'] . '" topLeftCell="A' . ((int)$sh['freeze_row'] + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '';
        $files["xl/worksheets/sheet$n.xml"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $freeze . ($cols ? "<cols>$cols</cols>" : '') . "<sheetData>$sheetData</sheetData>$merges</worksheet>";
        $ctSheets .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . _xmlEsc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbRels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $nStyles = count($sheets) + 1;
    $wbRels .= '<Relationship Id="rId' . $nStyles . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $ctSheets . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . "<sheets>$wbSheets</sheets></workbook>";
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>';
    $files['xl/styles.xml'] = $styles;

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $p => $content) $zip->addFromString($p, $content);
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
