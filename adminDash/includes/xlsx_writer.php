<?php
// Builds a simple one-sheet Excel (.xlsx) file and sends it as a download.
// Used by the voter template and the department voter list exports.
if (!defined('BASE_DIR')) {
    exit;
}

/**
 * @param string $filename      Download name, e.g. "voters.xlsx"
 * @param string $sheet_name    Name of the worksheet tab
 * @param array  $headers       Header row labels (shown in bold, row stays visible when scrolling)
 * @param array  $rows          Data rows, each a list of values (ints become numbers, the rest text)
 * @param array  $widths        Column widths, one per header
 * @param array  $text_columns  Column indexes formatted as text even when empty (so typed IDs
 *                              like 24-0123 stay as typed)
 */
function send_xlsx($filename, $sheet_name, array $headers, array $rows, array $widths, array $text_columns = [])
{
    $xml = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $col_letter = fn($index) => chr(65 + $index);   // up to 26 columns is plenty here

    // Styles: 0 = normal, 1 = text format, 2 = bold header
    $cols = '';
    foreach ($widths as $i => $width) {
        $style = in_array($i, $text_columns, true) ? ' style="1"' : '';
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '"' . $style . ' customWidth="1"/>';
    }

    $sheet_rows = '<row r="1">';
    foreach ($headers as $i => $header) {
        $sheet_rows .= '<c r="' . $col_letter($i) . '1" t="inlineStr" s="2"><is><t>' . $xml($header) . '</t></is></c>';
    }
    $sheet_rows .= '</row>';

    foreach (array_values($rows) as $r => $row) {
        $row_number = $r + 2;
        $sheet_rows .= '<row r="' . $row_number . '">';
        foreach (array_values($row) as $i => $value) {
            $ref = $col_letter($i) . $row_number;
            $sheet_rows .= is_int($value)
                ? '<c r="' . $ref . '"><v>' . $value . '</v></c>'
                : '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $xml($value) . '</t></is></c>';
        }
        $sheet_rows .= '</row>';
    }

    $main_ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel_ns  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $head    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    $parts = [
        '[Content_Types].xml' => $head
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',

        '_rels/.rels' => $head
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel_ns . '/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' => $head
            . '<workbook xmlns="' . $main_ns . '" xmlns:r="' . $rel_ns . '">'
            . '<sheets><sheet name="' . $xml(mb_substr($sheet_name, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>',

        'xl/_rels/workbook.xml.rels' => $head
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel_ns . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel_ns . '/styles" Target="styles.xml"/>'
            . '</Relationships>',

        'xl/styles.xml' => $head
            . '<styleSheet xmlns="' . $main_ns . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>',

        // Header row is frozen so it stays visible when scrolling
        'xl/worksheets/sheet1.xml' => $head
            . '<worksheet xmlns="' . $main_ns . '">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $sheet_rows . '</sheetData>'
            . '</worksheet>',
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($parts as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}
