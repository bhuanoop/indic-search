<?php

namespace IndicSearch;

use IndicSearch\NLP\Devanagari;

/**
 * Multilingual Indic Tokenizer
 * 
 * Tokenizes Devanagari and Latin text into search tokens, computes term frequencies (TF),
 * and generates both surface and morphological stem variants.
 */
class Tokenizer
{
    /**
     * Tokenize text into search terms.
     * 
     * @param string $text
     * @return array{tf: array<string, int>, surface: array<string, bool>}
     */
    public static function tokenize(string $text): array
    {
        $text = mb_strtolower((string) $text, 'UTF-8');
        $tokens = [];
        $surface = [];

        // 1. Devanagari tokens (letters and diacritics)
        if (preg_match_all('/[\x{0900}-\x{0963}\x{0971}-\x{097F}]+/u', $text, $m)) {
            foreach ($m[0] as $word) {
                $norm = Devanagari::normalizeWord($word);
                if ($norm === '') {
                    continue;
                }
                $clusters = Devanagari::splitClusters($norm);
                $k = count($clusters);
                if ($k < 2 || $k > 24) {
                    continue; // 1-character words are noise/prepositions; 24+ are garbage/concatenations
                }

                $tokens[$norm] = ($tokens[$norm] ?? 0) + 1;
                $surface[$norm] = true;

                $stem = Devanagari::stemWord($norm);
                if ($stem !== $norm) {
                    $tokens[$stem] = ($tokens[$stem] ?? 0) + 1;
                }
            }
        }

        // 2. Latin / English / Hinglish tokens (3+ chars)
        if (preg_match_all('/[a-z][a-z0-9]{2,}/', $text, $m2)) {
            foreach ($m2[0] as $word) {
                if (strlen($word) > 60) {
                    continue;
                }
                $tokens[$word] = ($tokens[$word] ?? 0) + 1;
                $surface[$word] = true;
            }
        }

        return ['tf' => $tokens, 'surface' => $surface];
    }

    /**
     * Parse query into structured search units with script and cluster count metadata.
     * 
     * @param string $query
     * @return array<int, array{raw: string, norm: string, hi: bool, k: int}>
     */
    public static function parseQuery(string $query): array
    {
        $words = [];
        preg_match_all('/[\x{0900}-\x{0963}\x{0971}-\x{097F}]+|[a-zA-Z][a-zA-Z0-9]*/u', $query, $m);

        foreach ($m[0] as $w) {
            $norm = Devanagari::normalizeWord(mb_strtolower($w, 'UTF-8'));
            if ($norm === '') {
                continue;
            }
            $isHi = Devanagari::hasDevanagari($norm);
            $k = $isHi ? count(Devanagari::splitClusters($norm)) : mb_strlen($norm, 'UTF-8');
            if ($k < 2 || $k > 24) {
                continue;
            }
            $words[] = [
                'raw'  => $w,
                'norm' => $norm,
                'hi'   => $isHi,
                'k'    => $k,
            ];
        }

        return $words;
    }

    /**
     * Extract headings (h1-h4) text from HTML content.
     */
    public static function extractHeadings(string $html): string
    {
        if (preg_match_all('/<h[1-4]\b[^>]*>(.*?)<\/h[1-4]>/is', $html, $m)) {
            return strip_tags(implode(' ', $m[1]));
        }
        return '';
    }

    /**
     * Strip HTML safely.
     */
    public static function cleanHtml(string $html): string
    {
        return trim(strip_tags($html));
    }
}
