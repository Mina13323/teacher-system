<?php

namespace App\Services\Export;

/**
 * Dependency-free XLSX reader (pairs with XlsxWriter for bulk import).
 *
 * Parses standard Office Open XML workbooks without ext-zip or PhpSpreadsheet:
 *  - Walks the ZIP central directory (or local headers as fallback), supporting
 *    both stored (method 0) and deflate-compressed (method 8, via gzinflate)
 *    entries produced by Excel, LibreOffice, Google Sheets, and XlsxWriter.
 *  - Resolves shared strings (`xl/sharedStrings.xml`, t="s"), inline strings
 *    (`t="inlineStr"` / `<is><t>`), direct string results (`t="str"`), and
 *    numeric/general `<v>` cells.
 *  - Honours sparse cell references (`r="C5"`) so empty leading/middle columns
 *    stay aligned to their true column index.
 */
final class XlsxReader
{
    /**
     * Parse raw XLSX binary bytes into a list of rows (each row is a list of trimmed strings).
     *
     * @return list<list<string>>
     */
    public static function readRows(string $bytes): array
    {
        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== 'PK') {
            throw new \InvalidArgumentException('Invalid XLSX archive.');
        }

        $entries = self::unzip($bytes);

        $sharedStrings = [];
        if (isset($entries['xl/sharedStrings.xml'])) {
            $sharedStrings = self::parseSharedStrings($entries['xl/sharedStrings.xml']);
        }

        $sheetXml = $entries['xl/worksheets/sheet1.xml'] ?? null;
        if ($sheetXml === null) {
            foreach ($entries as $name => $content) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheetXml = $content;
                    break;
                }
            }
        }

        if ($sheetXml === null) {
            throw new \InvalidArgumentException('XLSX archive contains no worksheet.');
        }

        return self::parseSheet($sheetXml, $sharedStrings);
    }

    /**
     * Extract XML entries from a ZIP archive using central directory (handles
     * data descriptors / streaming flags used by Excel) with local-header fallback.
     *
     * @return array<string, string>
     */
    private static function unzip(string $bytes): array
    {
        $entries = [];
        $len = strlen($bytes);

        // Locate End of Central Directory (EOCD) signature 0x06054b50 in the last 65KB.
        $searchStart = max(0, $len - 65557);
        $eocdPos = strrpos(substr($bytes, $searchStart), "PK\x05\x06");

        if ($eocdPos !== false) {
            $eocdOffset = $searchStart + $eocdPos;
            if ($eocdOffset + 22 <= $len) {
                $eocd = unpack('vdisk/vcdDisk/viskEntries/vtotalEntries/VcdSize/VcdOffset', substr($bytes, $eocdOffset + 4, 16));
                $pos = $eocd['cdOffset'];
                $cdEnd = $pos + $eocd['cdSize'];

                while ($pos + 46 <= $len && $pos < $cdEnd && substr($bytes, $pos, 4) === "PK\x01\x02") {
                    $hdr = unpack(
                        'vverMade/vverNeed/vflags/vmethod/vmodTime/vmodDate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalOffset',
                        substr($bytes, $pos + 4, 42)
                    );
                    $name = substr($bytes, $pos + 46, $hdr['nameLen']);
                    $pos += 46 + $hdr['nameLen'] + $hdr['extraLen'] + $hdr['commentLen'];

                    $localPos = $hdr['localOffset'];
                    if ($localPos + 30 > $len || substr($bytes, $localPos, 4) !== "PK\x03\x04") {
                        continue;
                    }
                    $lhdr = unpack('vver/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen', substr($bytes, $localPos + 4, 26));
                    $dataStart = $localPos + 30 + $lhdr['nameLen'] + $lhdr['extraLen'];
                    $raw = substr($bytes, $dataStart, $hdr['compSize']);

                    $decoded = self::decodeEntry($raw, (int) $hdr['method']);
                    if ($decoded !== null) {
                        $entries[$name] = $decoded;
                    }
                }

                if ($entries !== []) {
                    return $entries;
                }
            }
        }

        // Fallback: walk local file headers sequentially.
        $pos = 0;
        while ($pos + 30 <= $len && substr($bytes, $pos, 4) === "PK\x03\x04") {
            $hdr = unpack('vver/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen', substr($bytes, $pos + 4, 26));
            $name = substr($bytes, $pos + 30, $hdr['nameLen']);
            $dataStart = $pos + 30 + $hdr['nameLen'] + $hdr['extraLen'];
            $raw = substr($bytes, $dataStart, $hdr['compSize']);
            $pos = $dataStart + $hdr['compSize'];

            $decoded = self::decodeEntry($raw, (int) $hdr['method']);
            if ($decoded !== null) {
                $entries[$name] = $decoded;
            }
        }

        return $entries;
    }

    private static function decodeEntry(string $raw, int $method): ?string
    {
        if ($method === 0) {
            return $raw;
        }
        if ($method === 8) {
            $out = @gzinflate($raw);
            return $out === false ? null : $out;
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private static function parseSharedStrings(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            return [];
        }

        $doc->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $items = $doc->xpath('//s:si') ?: $doc->xpath('//si') ?: [];

        $strings = [];
        foreach ($items as $si) {
            $si->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $tNodes = $si->xpath('.//s:t') ?: $si->xpath('.//t') ?: [];
            $buf = '';
            foreach ($tNodes as $t) {
                $buf .= (string) $t;
            }
            $strings[] = $buf;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<list<string>>
     */
    private static function parseSheet(string $xml, array $sharedStrings): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            throw new \InvalidArgumentException('Malformed worksheet XML.');
        }

        $doc->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rowNodes = $doc->xpath('//s:sheetData/s:row') ?: $doc->xpath('//sheetData/row') ?: [];

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $rowNode->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $cellNodes = $rowNode->xpath('./s:c') ?: $rowNode->xpath('./c') ?: [];

            $cells = [];
            $nextCol = 0;

            foreach ($cellNodes as $cell) {
                $attrs = $cell->attributes();
                $ref = isset($attrs['r']) ? (string) $attrs['r'] : '';
                $type = isset($attrs['t']) ? (string) $attrs['t'] : '';

                $colIdx = $ref !== '' ? self::colIndexFromRef($ref, $nextCol) : $nextCol;
                while ($nextCol < $colIdx) {
                    $cells[$nextCol] = '';
                    $nextCol++;
                }

                $val = '';
                if ($type === 'inlineStr') {
                    $cell->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $tNodes = $cell->xpath('.//s:t') ?: $cell->xpath('.//t') ?: [];
                    foreach ($tNodes as $t) {
                        $val .= (string) $t;
                    }
                } elseif ($type === 's') {
                    $idx = (int) ((string) ($cell->v ?? '0'));
                    $val = $sharedStrings[$idx] ?? '';
                } else {
                    $val = isset($cell->v) ? (string) $cell->v : '';
                }

                $cells[$colIdx] = trim($val);
                $nextCol = $colIdx + 1;
            }

            // Skip completely blank rows
            $nonEmpty = array_filter($cells, fn ($v) => $v !== '');
            if ($nonEmpty !== []) {
                ksort($cells);
                $rows[] = array_values($cells);
            }
        }

        return $rows;
    }

    private static function colIndexFromRef(string $ref, int $fallback): int
    {
        if (! preg_match('/^([A-Z]+)/i', $ref, $m)) {
            return $fallback;
        }
        $letters = strtoupper($m[1]);
        $idx = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $idx - 1);
    }
}
