<?php

namespace IndicSearch\NLP;

/**
 * Devanagari NLP Engine
 * 
 * Handles Devanagari script normalization, grapheme cluster segmentation,
 * phonetic similarity evaluation, weighted cluster edit distance, and light morphological stemming.
 */
class Devanagari
{
    /**
     * Normalize Devanagari text:
     * - Decompose precomposed characters with nukta (e.g. क़ → क + ़) then strip nukta
     * - Standardize chandrabindu (ँ) to anusvara (ं)
     * - Remove Zero-Width Joiner (ZWJ) and Zero-Width Non-Joiner (ZWNJ)
     * - Trim whitespace
     */
    public static function normalizeWord(string $word): string
    {
        $word = trim($word);
        if ($word === '') {
            return '';
        }

        // 1. Decompose precomposed Devanagari characters with nukta
        $word = str_replace(
            ["\u{0958}", "\u{0959}", "\u{095A}", "\u{095B}", "\u{095C}", "\u{095D}", "\u{095E}", "\u{095F}"],
            ["\u{0915}\u{093C}", "\u{0916}\u{093C}", "\u{0917}\u{093C}", "\u{091C}\u{093C}", "\u{0921}\u{093C}", "\u{0922}\u{093C}", "\u{092B}\u{093C}", "\u{092F}\u{093C}"],
            $word
        );

        // 2. Normalize chandrabindu to anusvara (ँ → ं)
        $word = str_replace(["\u{0901}"], "\u{0902}", $word);

        // 3. Remove nukta (़)
        $word = str_replace(["\u{093C}"], '', $word);

        // 4. Remove ZWNJ and ZWJ
        $word = str_replace(["\u{200C}", "\u{200D}"], '', $word);

        return $word;
    }

    /**
     * Split a Devanagari word into "Akshara Clusters" (grapheme units).
     * Each cluster = base consonant/vowel + attached matras/virama/anusvara/visarga.
     * Example: "खांसी" → ["खां", "सी"], "बुखार" → ["बु", "खा", "र"].
     * 
     * @return string[]
     */
    public static function splitClusters(string $word): array
    {
        if ($word === '') {
            return [];
        }

        preg_match_all(
            '/[\x{0904}-\x{0939}\x{0958}-\x{0961}\x{0971}-\x{097F}][\x{0900}-\x{0903}\x{093A}-\x{094F}\x{0951}-\x{0957}\x{0962}\x{0963}]*/u',
            $word,
            $matches
        );

        return $matches[0] ?? [];
    }

    /**
     * Check if a string contains any Devanagari characters.
     */
    public static function hasDevanagari(string $str): bool
    {
        return (bool) preg_match('/[\x{0900}-\x{097F}]/u', $str);
    }

    /**
     * Check if two Devanagari base consonants are phonetically and orthographically similar.
     * (E.g. aspirated/unaspirated pairs like क↔ख, sibilants स↔श↔ष, nasals न↔ण).
     */
    public static function areSimilarBases(string $a, string $b): bool
    {
        static $pairs = null;
        if ($pairs === null) {
            $groups = [
                ['क', 'ख'], ['ग', 'घ'], ['च', 'छ'], ['ज', 'झ'], ['ट', 'ठ'],
                ['ड', 'ढ'], ['त', 'थ'], ['द', 'ध'], ['प', 'फ'], ['ब', 'भ'],
                ['स', 'श', 'ष'], ['न', 'ण'], ['ल', 'ळ'], ['व', 'ब'],
            ];
            $pairs = [];
            foreach ($groups as $group) {
                foreach ($group as $x) {
                    foreach ($group as $y) {
                        if ($x !== $y) {
                            $pairs[$x . '|' . $y] = true;
                        }
                    }
                }
            }
        }

        return isset($pairs[$a . '|' . $b]);
    }

    /**
     * Substitution cost between two Devanagari akshara clusters:
     * - Exact identical cluster          → 0.0
     * - Only anusvara (ं) difference     → 0.25 (most common typo: खांसी/खासी)
     * - Same base, different matra       → 0.40
     * - Similar base, same matra         → 0.35 (second most common typo: जुकाम/जुखाम)
     * - Similar base, different matra    → 0.70
     * - Completely distinct cluster      → 1.00
     */
    public static function clusterSubCost(string $x, string $y): float
    {
        if ($x === $y) {
            return 0.0;
        }

        $bx = mb_substr($x, 0, 1, 'UTF-8');
        $by = mb_substr($y, 0, 1, 'UTF-8');

        if ($bx === $by) {
            // Difference is only anusvara?
            if (str_replace("\u{0902}", '', $x) === str_replace("\u{0902}", '', $y)) {
                return 0.25;
            }
            return 0.40;
        }

        if (self::areSimilarBases($bx, $by)) {
            $mx = mb_substr($x, 1, null, 'UTF-8');
            $my = mb_substr($y, 1, null, 'UTF-8');
            return ($mx === $my) ? 0.35 : 0.70;
        }

        return 1.0;
    }

    /**
     * Compute weighted Levenshtein distance across Devanagari clusters.
     * 
     * @param string[] $clustersA
     * @param string[] $clustersB
     */
    public static function clusterDistance(array $clustersA, array $clustersB): float
    {
        $n = count($clustersA);
        $m = count($clustersB);

        if ($n === 0) return (float) $m;
        if ($m === 0) return (float) $n;

        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = self::clusterSubCost($clustersA[$i - 1], $clustersB[$j - 1]);
                $cur[$j] = min(
                    $prev[$j] + 1.0,         // Deletion
                    $cur[$j - 1] + 1.0,      // Insertion
                    $prev[$j - 1] + $cost    // Weighted Substitution
                );
            }
            $prev = $cur;
        }

        return (float) $prev[$m];
    }

    /**
     * Light Devanagari stemming (inflectional suffix stripping).
     * Handles Hindi plural, oblique, and case endings (e.g. ाइयों, ियां, ों, ें, etc.).
     * Preserves stems with at least 2 akshara clusters.
     */
    public static function stemWord(string $norm): string
    {
        static $suffixes = null;
        if ($suffixes === null) {
            // Longest suffixes first
            $suffixes = [
                'इयां', 'इयों', 'ाइयों', 'ाइयां', 'ियों', 'ियां', 'ाओं', 'ुओं', 'ाएं', 'ाई', 'ाए', 'ाओ',
                'ओं', 'ां', 'ों', 'ें', 'ीं',
                'ो', 'े', 'ू', 'ु', 'ी', 'ि', 'ा', 'ं',
            ];
        }

        foreach ($suffixes as $suf) {
            $len = mb_strlen($suf, 'UTF-8');
            if (mb_substr($norm, -$len, null, 'UTF-8') !== $suf) {
                continue;
            }

            $stem = mb_substr($norm, 0, mb_strlen($norm, 'UTF-8') - $len, 'UTF-8');
            if (count(self::splitClusters($stem)) >= 2) {
                return $stem;
            }
            return $norm; // Stem would be too short
        }

        return $norm;
    }
}
