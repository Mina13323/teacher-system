<?php

namespace App\Services\Export;

/**
 * Minimal TrueType reader for PDF font embedding (P2 native export).
 *
 * Reads only what the PDF writer needs: units per em, glyph advance widths,
 * unicode -> glyph id mapping (cmap format 4/12), vertical metrics and the
 * bounding box. No external libraries.
 */
final class TrueTypeFont
{
    private string $data;

    private int $unitsPerEm;

    private int $numGlyphs;

    /** @var array<int, int> codepoint => glyph id */
    private array $cmap = [];

    /** @var array<int, int> glyph id => advance width (font units) */
    private array $advances = [];

    public int $ascender;

    public int $descender;

    /** @var array{0: int, 1: int, 2: int, 3: int} xMin, yMin, xMax, yMax (font units) */
    public array $bbox;

    private function __construct(string $data)
    {
        $this->data = $data;
        $this->parse();
    }

    public static function load(string $path): self
    {
        $data = file_get_contents($path);
        if ($data === false || strlen($data) < 12) {
            throw new \RuntimeException("Unable to read font file: {$path}");
        }

        return new self($data);
    }

    public function unitsPerEm(): int
    {
        return $this->unitsPerEm;
    }

    public function glyphId(int $codepoint): int
    {
        return $this->cmap[$codepoint] ?? 0;
    }

    public function advanceWidth(int $glyphId): int
    {
        return $this->advances[$glyphId] ?? ($this->advances[0] ?? $this->unitsPerEm);
    }

    /** Width in 1/1000 text-space units for the PDF /W array. */
    public function widthPerMille(int $glyphId): int
    {
        return (int) round($this->advanceWidth($glyphId) * 1000 / $this->unitsPerEm);
    }

    public function raw(): string
    {
        return $this->data;
    }

    private function parse(): void
    {
        $d = $this->data;
        $numTables = $this->u16(4);
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $o = 12 + 16 * $i;
            $tag = substr($d, $o, 4);
            $tables[$tag] = [$this->u32($o + 8), $this->u32($o + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap'] as $required) {
            if (! isset($tables[$required])) {
                throw new \RuntimeException('TrueType font is missing the ' . $required . ' table');
            }
        }

        [$head] = $tables['head'];
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox = [
            $this->s16($head + 36),
            $this->s16($head + 38),
            $this->s16($head + 40),
            $this->s16($head + 42),
        ];

        [$hhea] = $tables['hhea'];
        $this->ascender = $this->s16($hhea + 4);
        $this->descender = $this->s16($hhea + 6);
        $numHMetrics = $this->u16($hhea + 34);

        [$maxp] = $tables['maxp'];
        $this->numGlyphs = $this->u16($maxp + 4);

        [$hmtx] = $tables['hmtx'];
        $last = 0;
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            if ($g < $numHMetrics) {
                $last = $this->u16($hmtx + 4 * $g);
                $this->advances[$g] = $last;
            } else {
                $this->advances[$g] = $last;
            }
        }

        $this->parseCmap($tables['cmap'][0]);
    }

    private function parseCmap(int $cmap): void
    {
        $n = $this->u16($cmap + 2);
        $best = null;
        for ($i = 0; $i < $n; $i++) {
            $o = $cmap + 4 + 8 * $i;
            $platform = $this->u16($o);
            $encoding = $this->u16($o + 2);
            $off = $cmap + $this->u32($o + 4);
            $format = $this->u16($off);
            $score = 0;
            if ($platform === 3 && $encoding === 10 && $format === 12) {
                $score = 4;
            } elseif ($platform === 3 && $encoding === 1 && $format === 4) {
                $score = 3;
            } elseif ($platform === 0 && $format === 12) {
                $score = 3;
            } elseif ($platform === 0 && $format === 4) {
                $score = 2;
            }
            if ($score > 0 && ($best === null || $score > $best[0])) {
                $best = [$score, $off, $format];
            }
        }
        if ($best === null) {
            throw new \RuntimeException('No usable cmap subtable found');
        }
        [, $off, $format] = $best;

        if ($format === 4) {
            $segX2 = $this->u16($off + 6);
            $segs = intdiv($segX2, 2);
            $endO = $off + 14;
            $startO = $endO + $segX2 + 2;
            $deltaO = $startO + $segX2;
            $rangeO = $deltaO + $segX2;
            for ($s = 0; $s < $segs; $s++) {
                $end = $this->u16($endO + 2 * $s);
                $start = $this->u16($startO + 2 * $s);
                $delta = $this->u16($deltaO + 2 * $s);
                $rangeOffset = $this->u16($rangeO + 2 * $s);
                for ($c = $start; $c <= $end && $c <= 0xFFFF; $c++) {
                    if ($rangeOffset === 0) {
                        $g = ($c + $delta) & 0xFFFF;
                    } else {
                        $gi = $rangeO + 2 * $s + $rangeOffset + 2 * ($c - $start);
                        $g = $this->u16($gi);
                        if ($g !== 0) {
                            $g = ($g + $delta) & 0xFFFF;
                        }
                    }
                    if ($g !== 0 && ! isset($this->cmap[$c])) {
                        $this->cmap[$c] = $g;
                    }
                }
            }
        } elseif ($format === 12) {
            $groups = $this->u32($off + 12);
            for ($g = 0; $g < $groups; $g++) {
                $go = $off + 16 + 12 * $g;
                $start = $this->u32($go);
                $end = $this->u32($go + 4);
                $startGlyph = $this->u32($go + 8);
                for ($c = $start; $c <= $end; $c++) {
                    if (! isset($this->cmap[$c])) {
                        $this->cmap[$c] = $startGlyph + ($c - $start);
                    }
                }
            }
        } else {
            throw new \RuntimeException('Unsupported cmap format: ' . $format);
        }
    }

    private function u16(int $o): int
    {
        return unpack('n', substr($this->data, $o, 2))[1];
    }

    private function u32(int $o): int
    {
        return unpack('N', substr($this->data, $o, 4))[1];
    }

    private function s16(int $o): int
    {
        $v = unpack('n', substr($this->data, $o, 2))[1];

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }
}
