<?php

namespace IndicSearch\Highlighting;

use IndicSearch\NLP\Devanagari;

/**
 * Text Highlighter & Snippet Generator
 * 
 * Generates highlighted search terms and context-aware snippets
 * with UTF-8 safety and Devanagari character boundary preservation.
 */
class Highlighter
{
    /**
     * Highlight query terms in a text string.
     * 
     * @param string $text
     * @param string[] $terms
     * @param string $highlightTag Default 'mark'
     * @param string $cssClass Default 'indic-highlight'
     * @return string
     */
    public static function highlight(string $text, array $terms, string $highlightTag = 'mark', string $cssClass = 'indic-highlight'): string
    {
        if ($text === '' || empty($terms)) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }

        // Clean and prepare unique search terms
        $patterns = [];
        foreach ($terms as $t) {
            $t = trim((string)$t);
            if (mb_strlen($t, 'UTF-8') >= 2) {
                $patterns[] = preg_quote($t, '/');
            }
        }

        if (empty($patterns)) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }

        // Sort by length descending so longer words match first
        usort($patterns, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        $regex = '/(' . implode('|', $patterns) . ')/iu';

        // Escape HTML first to prevent XSS
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $openTag = "<{$highlightTag} class=\"{$cssClass}\">";
        $closeTag = "</{$highlightTag}>";

        return preg_replace($regex, $openTag . '$1' . $closeTag, $escaped);
    }

    /**
     * Create a context snippet around the first matched search term.
     * 
     * @param string $htmlContent Raw HTML or plain text
     * @param string[] $terms
     * @param int $snippetLength Target character length
     * @return string
     */
    public static function createSnippet(string $htmlContent, array $terms, int $snippetLength = 160): string
    {
        $plainText = trim(strip_tags($htmlContent));
        $plainText = preg_replace('/\s+/u', ' ', $plainText);

        if ($plainText === '') {
            return '';
        }

        if (empty($terms)) {
            return mb_substr($plainText, 0, $snippetLength, 'UTF-8') . (mb_strlen($plainText, 'UTF-8') > $snippetLength ? '...' : '');
        }

        // Find the earliest position of any term
        $bestPos = null;
        foreach ($terms as $t) {
            $t = trim((string)$t);
            if (mb_strlen($t, 'UTF-8') < 2) continue;
            $pos = mb_stripos($plainText, $t, 0, 'UTF-8');
            if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
                $bestPos = $pos;
            }
        }

        if ($bestPos === null) {
            // No term found, return beginning of text
            $snippet = mb_substr($plainText, 0, $snippetLength, 'UTF-8');
            return self::highlight($snippet, $terms) . (mb_strlen($plainText, 'UTF-8') > $snippetLength ? '...' : '');
        }

        // Center snippet around the match
        $half = (int) floor($snippetLength / 2);
        $start = max(0, $bestPos - $half);
        $prefix = $start > 0 ? '...' : '';

        // Align start to word boundary if possible
        if ($start > 0) {
            $spacePos = mb_strpos($plainText, ' ', $start, 'UTF-8');
            if ($spacePos !== false && $spacePos < $bestPos) {
                $start = $spacePos + 1;
            }
        }

        $snippet = mb_substr($plainText, $start, $snippetLength, 'UTF-8');
        $suffix = (mb_strlen($plainText, 'UTF-8') > ($start + mb_strlen($snippet, 'UTF-8'))) ? '...' : '';

        return $prefix . self::highlight($snippet, $terms) . $suffix;
    }
}
