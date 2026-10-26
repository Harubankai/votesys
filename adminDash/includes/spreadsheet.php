<?php
// Reads uploaded voter lists: the first sheet of an Excel .xlsx file, or a .csv file.
// Rows come back keyed by their spreadsheet row number (1 = first row),
// each row a list of trimmed cell strings. Returns null if the file can't be read.
if (!defined('BASE_DIR')) {
    exit;
}

// Limits that keep a bad or booby-trapped file from using up the server's memory
const XLSX_MAX_PART_SIZE = 50 * 1024 * 1024;   // a small upload can unzip to gigabytes ("zip bomb")
const SPREADSHEET_MAX_COLUMNS = 50;            // voter lists only need a handful of columns

function read_spreadsheet($path, $extension)
{
    return $extension === 'csv' ? read_csv_rows($path) : read_xlsx_rows($path);
}

function read_csv_rows($path)
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        return null;
    }

    // Skip the byte-order mark Excel adds when saving as "CSV UTF-8"
    if (fread($handle, 3) !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $rows = [];
    $row_number = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $rows[++$row_number] = array_map(fn($value) => trim((string) $value), $row);
    }
    fclose($handle);

    return $rows;
}

function read_xlsx_rows($path)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }

    // Most text cells store an index into this shared list instead of the text itself
    $shared_strings = [];
    $xml = zip_entry($zip, 'xl/sharedStrings.xml');
    if ($xml !== false) {
        $strings = parse_xml($xml);
        if ($strings === null) {
            $zip->close();
            return null;
        }
        foreach ($strings->si as $item) {
            $shared_strings[] = xml_text($item);
        }
    }

    $sheet_xml = zip_entry($zip, first_sheet_path($zip));
    $zip->close();
    $sheet = $sheet_xml === false ? null : parse_xml($sheet_xml);
    if ($sheet === null || !isset($sheet->sheetData)) {
        return null;
    }

    $rows = [];
    $next_row = 1;
    foreach ($sheet->sheetData->row as $row) {
        $row_number = isset($row['r']) ? (int) $row['r'] : $next_row;
        $next_row = $row_number + 1;

        $cells = [];
        $next_column = 0;
        foreach ($row->c as $cell) {
            $column = isset($cell['r']) ? column_index((string) $cell['r']) : $next_column;
            $next_column = $column + 1;
            if ($column < 0 || $column >= SPREADSHEET_MAX_COLUMNS) {
                continue;
            }

            $type = (string) $cell['t'];
            if ($type === 's') {
                $value = $shared_strings[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($cell->is) ? xml_text($cell->is) : '';
            } else {
                $value = (string) $cell->v;
            }
            $cells[$column] = trim($value);
        }

        // Fill gaps so the row is a plain list (A, B, C, …)
        $rows[$row_number] = $cells ? array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells) : [];
    }

    return $rows;
}

// Finds the first sheet's file inside the .xlsx (usually xl/worksheets/sheet1.xml)
function first_sheet_path(ZipArchive $zip)
{
    $workbook = parse_xml((string) zip_entry($zip, 'xl/workbook.xml'));
    $rels = parse_xml((string) zip_entry($zip, 'xl/_rels/workbook.xml.rels'));
    if ($workbook === null || $rels === null) {
        return 'xl/worksheets/sheet1.xml';
    }

    $first_sheet = $workbook->sheets->sheet[0] ?? null;
    if ($first_sheet === null) {
        return 'xl/worksheets/sheet1.xml';
    }

    $rel_ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $rel_id = (string) $first_sheet->attributes($rel_ns)['id'];

    foreach ($rels->Relationship as $rel) {
        if ((string) $rel['Id'] === $rel_id) {
            $target = ltrim((string) $rel['Target'], '/');
            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
    }
    return 'xl/worksheets/sheet1.xml';
}

// One file from inside the .xlsx, or false if it's missing or unpacks too large
function zip_entry(ZipArchive $zip, $name)
{
    $stat = $zip->statName($name);
    if ($stat === false || $stat['size'] > XLSX_MAX_PART_SIZE) {
        return false;
    }
    return $zip->getFromName($name);
}

// Parsed XML, or null if it isn't valid. Never fetches anything from the network.
function parse_xml($xml)
{
    if ($xml === '') {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $doc === false ? null : $doc;
}

// All <t> text inside a cell, including rich-text runs
function xml_text(SimpleXMLElement $element)
{
    $text = '';
    foreach ($element->xpath('.//*[local-name()="t"]') as $t) {
        $text .= (string) $t;
    }
    return $text;
}

// "C12" -> 2 (A = 0)
function column_index($cell_ref)
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cell_ref));
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = $index * 26 + (ord($letter) - 64);
    }
    return $index - 1;
}
