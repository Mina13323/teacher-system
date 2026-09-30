<?php

namespace App\Services\Export;

/**
 * Dependency-free XLSX writer (P2 native export).
 *
 * Emits a real Office Open XML workbook (SpreadsheetML) wrapped in a minimal
 * stored-entry ZIP written byte-by-byte — no ext-zip, no PhpSpreadsheet.
 * UTF-8 throughout, so Arabic content is fully preserved.
 */
final class XlsxWriter
{
    /** @var array<int, array<int, string|int|float|null>> */
    private array $rows = [];

    private string $sheetName;

    private ?int $headerRow = null;

    public function __construct(string $sheetName = 'Sheet1')
    {
        $this->sheetName = $sheetName;
    }

    /** Mark the given row as the styled header row. */
    public function setHeaderRow(int $row): void
    {
        $this->headerRow = $row;
    }

    /**
     * @param  array<int, string|int|float|null>  $row
     */
    public function addRow(array $row): void
    {
        $this->rows[] = array_values($row);
    }

    public function output(): string
    {
        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->rels(),
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml' => $this->styles(),
            'xl/worksheets/sheet1.xml' => $this->sheet(),
        ];

        return self::zip($files);
    }

    private function sheet(): string
    {
        $maxCols = 1;
        foreach ($this->rows as $row) {
            $maxCols = max($maxCols, count($row));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="' . $maxCols . '" width="18" customWidth="1"/></cols>'
            . '<sheetData>';

        foreach ($this->rows as $i => $row) {
            $r = $i + 1;
            $xml .= '<row r="' . $r . '">';
            foreach ($row as $c => $value) {
                $ref = self::cellRef($c, $r);
                $style = ($this->headerRow !== null && $r === $this->headerRow) ? ' s="1"' : '';
                if ($value === null || $value === '') {
                    $xml .= '<c r="' . $ref . '"' . $style . '/>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                        . self::esc((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDE6DC"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '</styleSheet>';
    }

    private static function cellRef(int $col, int $row): string
    {
        $letters = '';
        $c = $col + 1;
        while ($c > 0) {
            $mod = ($c - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $c = intdiv($c - 1, 26);
        }

        return $letters . $row;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Minimal ZIP writer (stored entries, CRC-32). Enough for OOXML packages.
     *
     * @param  array<string, string>  $files  name => contents
     */
    private static function zip(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        foreach ($files as $name => $data) {
            $crc = crc32($data) & 0xFFFFFFFF;
            $size = strlen($data);
            $nameLen = strlen($name);
            $localHeader = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0)
                . $name;
            $local .= $localHeader . $data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset)
                . $name;
            $offset += strlen($localHeader) + $size;
        }
        $count = count($files);
        $end = pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($central), $offset, 0);

        return $local . $central . $end;
    }
}
