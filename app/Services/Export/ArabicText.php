<?php

namespace App\Services\Export;

/**
 * Arabic text preparation for PDF output (P2 native export).
 *
 * PDF places glyphs left-to-right in string order using WinAnsi/simple fonts,
 * and with Identity-H CID fonts in string order as well. Arabic therefore
 * needs two transformations before drawing:
 *
 *  1. Contextual shaping: each letter is replaced by its
 *     isolated/final/initial/medial presentation form (U+FE80..U+FEFC),
 *     including the lam-alef ligatures.
 *  2. Visual re-ordering: RTL runs are reversed at grapheme-cluster level
 *     (combining marks stay attached to their base letter).
 *
 * Simplified bidi: a run analysis alternates RTL and LTR runs; LTR runs keep
 * internal order. This is exactly what result tables (names + codes + numbers)
 * need. `medial` yeh is approximated with the initial form (Unicode reserves
 * U+FEF5..U+FEFC for the lam-alef ligatures in this font's cmap) — a single
 * connection-stroke artifact, documented in §30.
 */
final class ArabicText
{
    /** Letter => [isolated, final, initial, medial] presentation forms (0 = n/a). */
    private const FORMS = [
        "\u{0621}" => [0xFE80, 0, 0, 0],                      // hamza
        "\u{0622}" => [0xFE81, 0xFE82, 0, 0],                 // alef + madda
        "\u{0623}" => [0xFE83, 0xFE84, 0, 0],                 // alef + hamza above
        "\u{0624}" => [0xFE85, 0xFE86, 0, 0],                 // waw + hamza
        "\u{0625}" => [0xFE87, 0xFE88, 0, 0],                 // alef + hamza below
        "\u{0626}" => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C],       // yeh + hamza
        "\u{0627}" => [0xFE8D, 0xFE8E, 0, 0],                 // alef
        "\u{0628}" => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],       // beh
        "\u{0629}" => [0xFE93, 0xFE94, 0, 0],                 // teh marbuta
        "\u{062A}" => [0xFE95, 0xFE96, 0xFE97, 0xFE98],       // teh
        "\u{062B}" => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],       // theh
        "\u{062C}" => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],       // jeem
        "\u{062D}" => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],       // hah
        "\u{062E}" => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],       // khah
        "\u{062F}" => [0xFEAA, 0xFEAB, 0, 0],                 // dal
        "\u{0630}" => [0xFEAC, 0xFEAD, 0, 0],                 // thal
        "\u{0631}" => [0xFEAE, 0xFEAF, 0, 0],                 // reh
        "\u{0632}" => [0xFEB0, 0xFEB1, 0, 0],                 // zain
        "\u{0633}" => [0xFEB2, 0xFEB3, 0xFEB4, 0xFEB5],       // seen
        "\u{0634}" => [0xFEB6, 0xFEB7, 0xFEB8, 0xFEB9],       // sheen
        "\u{0635}" => [0xFEBA, 0xFEBB, 0xFEBC, 0xFEBD],       // sad
        "\u{0636}" => [0xFEBE, 0xFEBF, 0xFEC0, 0xFEC1],       // dad
        "\u{0637}" => [0xFEC2, 0xFEC3, 0xFEC4, 0xFEC5],       // tah
        "\u{0638}" => [0xFEC6, 0xFEC7, 0xFEC8, 0xFEC9],       // zah
        "\u{0639}" => [0xFECA, 0xFECB, 0xFECC, 0xFECD],       // ain
        "\u{063A}" => [0xFECE, 0xFECF, 0xFED0, 0xFED1],       // ghain
        "\u{0640}" => [0x0640, 0x0640, 0x0640, 0x0640],       // tatweel (joins both)
        "\u{0641}" => [0xFED2, 0xFED3, 0xFED4, 0xFED5],       // feh
        "\u{0642}" => [0xFED6, 0xFED7, 0xFED8, 0xFED9],       // qaf
        "\u{0643}" => [0xFEDA, 0xFEDB, 0xFEDC, 0xFEDD],       // kaf
        "\u{0644}" => [0xFEDE, 0xFEDF, 0xFEE0, 0xFEE1],       // lam
        "\u{0645}" => [0xFEE2, 0xFEE3, 0xFEE4, 0xFEE5],       // meem
        "\u{0646}" => [0xFEE6, 0xFEE7, 0xFEE8, 0xFEE9],       // noon
        "\u{0647}" => [0xFEEA, 0xFEEB, 0xFEEC, 0xFEED],       // heh
        "\u{0648}" => [0xFEEE, 0xFEEF, 0, 0],                 // waw
        "\u{0649}" => [0xFEF0, 0xFEF1, 0, 0],                 // alef maksura
        "\u{064A}" => [0xFEF2, 0xFEF3, 0xFEF4, 0xFEF4],       // yeh (medial ≈ initial)
    ];

    /** Lam-alef ligatures: alef-variant => [isolated, final]. */
    private const LAM_ALEF = [
        "\u{0622}" => [0xFEF5, 0xFEF6],
        "\u{0623}" => [0xFEF7, 0xFEF8],
        "\u{0625}" => [0xFEF9, 0xFEFA],
        "\u{0627}" => [0xFEFB, 0xFEFC],
    ];

    /** Combining marks (harakat, superscript marks) that stay with their base. */
    private const COMBINING = [
        "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}",
        "\u{0651}", "\u{0652}", "\u{0653}", "\u{0654}", "\u{0655}", "\u{0670}",
        "\u{06D6}", "\u{06D7}", "\u{06D8}", "\u{06D9}", "\u{06DA}", "\u{06DB}",
        "\u{06DC}", "\u{06DD}", "\u{06DE}", "\u{06DF}", "\u{06E0}", "\u{06E1}",
        "\u{06E2}", "\u{06E3}", "\u{06E4}", "\u{06E5}", "\u{06E6}", "\u{06E7}",
        "\u{06E8}", "\u{06E9}", "\u{06EA}", "\u{06EB}", "\u{06EC}", "\u{06ED}",
    ];

    /**
     * Shape + visually reorder `text` for PDF glyph output.
     * Returns a list of Unicode codepoints (ints) in visual order.
     *
     * @return array<int, int>
     */
    public static function toVisualCodepoints(string $text): array
    {
        $clusters = self::shapeClusters($text);
        $runs = self::bidiRuns($clusters);
        $out = [];
        foreach ($runs as $run) {
            foreach ($run['clusters'] as $cluster) {
                foreach ($cluster as $cp) {
                    $out[] = $cp;
                }
            }
        }

        return $out;
    }

    /**
     * True when the string contains non-ASCII characters requiring the
     * embedded Unicode font rather than a PDF core font.
     */
    public static function isUnicode(string $text): bool
    {
        return (bool) preg_match('/[\x80-\xFF]/', $text);
    }

    /**
     * Shape into grapheme clusters (base + combining marks), one cluster per
     * entry. Lam-alef ligatures collapse two letters into one cluster.
     *
     * @return list<list<int>> codepoints per cluster
     */
    private static function shapeClusters(string $text): array
    {
        $cps = self::codepoints($text);
        $clusters = [];
        $bases = []; // logical base char per cluster (shaped glyphs can't re-lookup FORMS)
        $n = count($cps);
        for ($i = 0; $i < $n; $i++) {
            $cp = $cps[$i];
            $ch = self::chr($cp);

            // lam + alef ligature
            if ($ch === "\u{0644}" && $i + 1 < $n) {
                $next = self::chr($cps[$i + 1]);
                if (isset(self::LAM_ALEF[$next])) {
                    $prevJoins = $i > 0 && self::joinsForward(self::chr($cps[$i - 1]));
                    $lig = self::LAM_ALEF[$next][$prevJoins ? 1 : 0];
                    $cluster = [$lig];
                    $i++; // consume alef
                    // marks after alef still attach
                    while ($i + 1 < $n && self::isMark(self::chr($cps[$i + 1]))) {
                        $cluster[] = $cps[++$i];
                    }
                    $clusters[] = $cluster;
                    // An isolated ligature cannot be joined from its left.
                    $bases[] = $prevJoins ? "\u{0644}" : "\u{0627}";
                    continue;
                }
            }

            $prevJoins = false;
            if (isset(self::FORMS[$ch])) {
                for ($j = count($bases) - 1; $j >= 0; $j--) {
                    if (! self::isMark($bases[$j])) {
                        $prevJoins = self::joinsForward($bases[$j]);
                        break;
                    }
                }
            }
            $nextJoins = false;
            for ($j = $i + 1; $j < $n; $j++) {
                $c = self::chr($cps[$j]);
                if (! self::isMark($c)) {
                    $nextJoins = self::joinsBackward($c) && self::joinsForward($ch);
                    break;
                }
            }

            $shaped = $cp;
            if (isset(self::FORMS[$ch]) && $ch !== "\u{0640}") {
                $forms = self::FORMS[$ch];
                if ($prevJoins && $nextJoins && $forms[3]) {
                    $shaped = $forms[3];
                } elseif ($prevJoins && $forms[1]) {
                    $shaped = $forms[1];
                } elseif ($nextJoins && $forms[2]) {
                    $shaped = $forms[2];
                } elseif ($forms[0]) {
                    $shaped = $forms[0];
                }
            }
            $cluster = [$shaped];
            while ($i + 1 < $n && self::isMark(self::chr($cps[$i + 1]))) {
                $cluster[] = $cps[++$i];
            }
            $clusters[] = $cluster;
            $bases[] = $ch;
        }

        return $clusters;
    }

    /**
     * Split shaped clusters into alternating directional runs and reverse the
     * RTL runs (visual order).
     *
     * @param  list<list<int>>  $clusters
     * @return list<array{rtl: bool, clusters: list<list<int>>}>
     */
    private static function bidiRuns(array $clusters): array
    {
        // Base direction: the first strong character decides paragraph order.
        $baseRtl = false;
        foreach ($clusters as $cluster) {
            if (self::isRtl($cluster[0])) {
                $baseRtl = true;
                break;
            }
            if (self::isStrongLtr($cluster[0])) {
                break;
            }
        }

        $runs = [];
        $current = null;
        foreach ($clusters as $cluster) {
            $first = $cluster[0];
            if (self::isRtl($first)) {
                $rtl = true;
            } elseif (self::isStrongLtr($first)) {
                $rtl = false;
            } else {
                // Neutral (space, digits, punctuation) inherits the current
                // run direction, defaulting to the paragraph direction.
                $rtl = $current['rtl'] ?? $baseRtl;
            }
            if ($current === null || $current['rtl'] !== $rtl) {
                if ($current !== null) {
                    $runs[] = $current;
                }
                $current = ['rtl' => $rtl, 'clusters' => []];
            }
            $current['clusters'][] = $cluster;
        }
        if ($current !== null) {
            $runs[] = $current;
        }

        if ($baseRtl) {
            $runs = array_reverse($runs);
        }
        foreach ($runs as &$run) {
            if ($run['rtl']) {
                $run['clusters'] = array_reverse($run['clusters']);
            }
        }
        unset($run);

        return $runs;
    }

    private static function isRtl(int $cp): bool
    {
        return ($cp >= 0x0600 && $cp <= 0x06FF)
            || ($cp >= 0x0750 && $cp <= 0x077F)
            || ($cp >= 0x08A0 && $cp <= 0x08FF)
            || ($cp >= 0xFB50 && $cp <= 0xFDFF)
            || ($cp >= 0xFE70 && $cp <= 0xFEFF);
    }

    private static function isStrongLtr(int $cp): bool
    {
        return ($cp >= 0x0041 && $cp <= 0x005A)
            || ($cp >= 0x0061 && $cp <= 0x007A)
            || ($cp >= 0x0030 && $cp <= 0x0039);
    }

    /** Can this letter connect to the FOLLOWING letter (dual-joining)? */
    private static function joinsForward(string $ch): bool
    {
        return isset(self::FORMS[$ch]) && self::FORMS[$ch][2] !== 0;
    }

    /** Can this letter connect to the PRECEDING letter (has a final form)? */
    private static function joinsBackward(string $ch): bool
    {
        return isset(self::FORMS[$ch]) && self::FORMS[$ch][1] !== 0;
    }

    private static function isMark(string $ch): bool
    {
        return in_array($ch, self::COMBINING, true);
    }

    /**
     * @return list<int>
     */
    private static function codepoints(string $text): array
    {
        $out = [];
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($text[$i]);
            if ($c < 0x80) {
                $out[] = $c;
            } elseif (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $out[] = (($c & 0x1F) << 6) | (ord($text[$i + 1]) & 0x3F);
                $i++;
            } elseif (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
                $out[] = (($c & 0x0F) << 12) | ((ord($text[$i + 1]) & 0x3F) << 6) | (ord($text[$i + 2]) & 0x3F);
                $i += 2;
            } elseif (($c & 0xF8) === 0xF0 && $i + 3 < $len) {
                $out[] = (($c & 0x07) << 18) | ((ord($text[$i + 1]) & 0x3F) << 12)
                    | ((ord($text[$i + 2]) & 0x3F) << 6) | (ord($text[$i + 3]) & 0x3F);
                $i += 3;
            }
        }

        return $out;
    }

    private static function chr(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }

        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
}
