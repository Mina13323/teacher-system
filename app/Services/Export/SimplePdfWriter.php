<?php

namespace App\Services\Export;

/**
 * Dependency-free PDF writer with embedded TrueType font (P2 native export).
 *
 * Produces real `application/pdf` bytes with an embedded CIDFontType2
 * (Identity-H) font so Arabic, shaped by {@see ArabicText} and drawn in
 * visual order, renders correctly. Top-left coordinate origin for callers.
 */
final class SimplePdfWriter
{
    private TrueTypeFont $font;

    private float $width;

    private float $height;

    /** @var list<array{content: string}> */
    private array $pages = [];

    private string $current = '';

    /** @var array<int, int> glyph id => unicode codepoint (for ToUnicode) */
    private array $usedGlyphs = [];

    public function __construct(TrueTypeFont $font, bool $landscape = true)
    {
        $this->font = $font;
        if ($landscape) {
            $this->width = 841.89;
            $this->height = 595.28;
        } else {
            $this->width = 595.28;
            $this->height = 841.89;
        }
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = ['content' => $this->current];
            $this->current = '';
        }
    }

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    /** Draw text with `x` from the left and `y` from the top. */
    public function text(float $x, float $yTop, string $text, float $size, string $color = '#000000', bool $rightAlign = false): void
    {
        if ($text === '') {
            return;
        }
        $visual = ArabicText::toVisualCodepoints($text);
        $hex = '';
        foreach ($visual as $cp) {
            $gid = $this->font->glyphId($cp);
            if ($gid === 0 && $cp !== 0x20) {
                $gid = $this->font->glyphId(0x20); // fall back to a space-width gap
            }
            $this->usedGlyphs[$gid] = $cp;
            $hex .= sprintf('%04X', $gid);
        }
        if ($rightAlign) {
            $x -= $this->measure($text, $size);
        }
        $y = $this->height - $yTop;
        [$r, $g, $b] = self::rgb($color);
        $this->current .= sprintf(
            "BT %.2f %.2f Td %.3f %.3f %.3f rg /F1 %.2f Tf <%s> Tj ET\n",
            $x,
            $y,
            $r,
            $g,
            $b,
            $size,
            $hex
        );
    }

    /** Width in points of `text` when drawn at `size`. */
    public function measure(string $text, float $size): float
    {
        $units = 0;
        foreach (ArabicText::toVisualCodepoints($text) as $cp) {
            $gid = $this->font->glyphId($cp);
            $units += $this->font->widthPerMille($gid);
        }

        return $units * $size / 1000.0;
    }

    public function rect(float $x, float $yTop, float $w, float $h, ?string $fill = null, ?string $stroke = null, float $lineWidth = 0.5): void
    {
        $y = $this->height - $yTop - $h;
        $op = '';
        if ($fill !== null) {
            [$r, $g, $b] = self::rgb($fill);
            $op .= sprintf('%.3f %.3f %.3f rg ', $r, $g, $b);
        }
        if ($stroke !== null) {
            [$r, $g, $b] = self::rgb($stroke);
            $op .= sprintf('%.3f %.3f %.3f RG %.2f w ', $r, $g, $b, $lineWidth);
        }
        $paint = $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->current .= sprintf("%s%.2f %.2f %.2f %.2f re %s\n", $op, $x, $y, $w, $h, $paint);
    }

    public function line(float $x1, float $y1Top, float $x2, float $y2Top, string $color = '#000000', float $lineWidth = 0.5): void
    {
        [$r, $g, $b] = self::rgb($color);
        $this->current .= sprintf(
            "%.2f w %.3f %.3f %.3f RG %.2f %.2f m %.2f %.2f l S\n",
            $lineWidth,
            $r,
            $g,
            $b,
            $x1,
            $this->height - $y1Top,
            $x2,
            $this->height - $y2Top
        );
    }

    /** Final PDF bytes. */
    public function output(): string
    {
        if ($this->current !== '') {
            $this->pages[] = ['content' => $this->current];
            $this->current = '';
        }

        $objects = [];
        // 1: catalog, 2: pages tree, 3: font (Type0), 4: CIDFont, 5: descriptor,
        // 6: font file, 7: ToUnicode — then one object per page + content.
        $pageIds = [];
        $contentIds = [];
        $next = 8;
        foreach ($this->pages as $i => $_) {
            $pageIds[$i] = $next++;
            $contentIds[$i] = $next++;
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = implode(' ', array_map(fn ($id) => "{$id} 0 R", $pageIds));
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($this->pages) . " >>";

        $objects[3] = "<< /Type /Font /Subtype /Type0 /BaseFont /Embedded /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 7 0 R >>";
        $objects[4] = "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Embedded /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 5 0 R /DW 500 /W [" . $this->wArray() . "] /CIDToGIDMap /Identity >>";

        $scale = 1000.0 / $this->font->unitsPerEm();
        [$xMin, $yMin, $xMax, $yMax] = $this->font->bbox;
        $bbox = sprintf('[%d %d %d %d]', (int) round($xMin * $scale), (int) round($yMin * $scale), (int) round($xMax * $scale), (int) round($yMax * $scale));
        $objects[5] = sprintf(
            '<< /Type /FontDescriptor /FontName /Embedded /Flags 32 /FontBBox %s /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 6 0 R >>',
            $bbox,
            (int) round($this->font->ascender * $scale),
            (int) round($this->font->descender * $scale),
            (int) round($this->font->ascender * $scale * 0.8)
        );

        $fontData = $this->font->raw();
        $objects[6] = $this->streamObject($fontData, ['Length1' => strlen($fontData)]);

        $objects[7] = $this->streamObject($this->toUnicodeCMap());

        foreach ($this->pages as $i => $page) {
            $objects[$pageIds[$i]] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>',
                $this->width,
                $this->height,
                $contentIds[$i]
            );
            $objects[$contentIds[$i]] = $this->streamObject($page['content']);
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }

    private function streamObject(string $data, array $extra = []): string
    {
        $dict = '';
        foreach ($extra as $k => $v) {
            $dict .= " /{$k} {$v}";
        }
        if (function_exists('gzcompress')) {
            $compressed = gzcompress($data, 6);
            if ($compressed !== false) {
                return '<<' . $dict . ' /Filter /FlateDecode /Length ' . strlen($compressed) . " >>\nstream\n" . $compressed . "\nendstream";
            }
        }

        return '<<' . $dict . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    private function wArray(): string
    {
        $gids = array_keys($this->usedGlyphs);
        sort($gids);
        if (! $gids) {
            return '';
        }
        $parts = [];
        $runStart = $gids[0];
        $run = [$gids[0]];
        for ($i = 1; $i <= count($gids); $i++) {
            $g = $gids[$i] ?? null;
            if ($g !== null && $g === end($run) + 1) {
                $run[] = $g;
                continue;
            }
            $widths = implode(' ', array_map(fn ($id) => $this->font->widthPerMille($id), $run));
            $parts[] = $runStart . ' [' . $widths . ']';
            if ($g !== null) {
                $runStart = $g;
                $run = [$g];
            }
        }

        return implode(' ', $parts);
    }

    private function toUnicodeCMap(): string
    {
        $entries = '';
        $gids = array_keys($this->usedGlyphs);
        sort($gids);
        foreach ($gids as $gid) {
            $cp = $this->usedGlyphs[$gid];
            $src = sprintf('<%04X>', $gid);
            // map to the pre-shaping codepoint where possible for clean copy/paste
            $dst = sprintf('<%04X>', $cp);
            $entries .= "{$src} {$dst}\n";
        }

        // PDF allows max 100 entries per beginbfchar block.
        $blocks = '';
        foreach (array_chunk(explode("\n", trim($entries)), 100) as $chunk) {
            $blocks .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $blocks
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
