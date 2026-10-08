<?php

namespace IndicSearch\Dictionary;

/**
 * Synonym Dictionary Manager
 * 
 * Expands query tokens to include Hindi, English, and Hinglish semantic variants
 * and phonetic typos (e.g. "bukhar" ↔ "fever" ↔ "बुखार" ↔ "ज्वर").
 */
class Synonyms
{
    /** @var array<int, string[]> Array of synonym groups */
    private array $groups = [];

    /**
     * @param string|null $jsonFilePath Optional path to a JSON file containing synonym groups
     */
    public function __construct(?string $jsonFilePath = null)
    {
        $defaultFile = __DIR__ . '/data/general_synonyms.json';
        if (file_exists($defaultFile)) {
            $this->loadFromFile($defaultFile);
        }

        if ($jsonFilePath !== null && file_exists($jsonFilePath)) {
            $this->loadFromFile($jsonFilePath);
        }
    }

    /**
     * Load synonym groups from a JSON file.
     */
    public function loadFromFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $data = json_decode(file_get_contents($filePath), true);
        if (is_array($data)) {
            foreach ($data as $entry) {
                $words = $entry['words'] ?? (is_array($entry) ? $entry : []);
                if (!empty($words) && is_array($words)) {
                    $this->addGroup($words);
                }
            }
        }
    }

    /**
     * Add a group of interchangeable synonyms.
     * 
     * @param string[] $words
     */
    public function addGroup(array $words): void
    {
        $cleaned = array_values(array_unique(array_filter(
            array_map(fn($w) => trim((string)$w), $words),
            fn($w) => $w !== ''
        )));

        if (count($cleaned) >= 2) {
            $this->groups[] = $cleaned;
        }
    }

    /**
     * Expand a query term using exact match and Latin fuzzy Levenshtein fallback.
     * 
     * @param string $word
     * @return string[] List of synonymous variants
     */
    public function expand(string $word): array
    {
        $word = mb_strtolower(trim($word), 'UTF-8');
        if ($word === '') {
            return [];
        }

        $isLatin = (bool) preg_match('/^[a-zA-Z\s]+$/', $word);

        // Pass 1: Exact match in dictionary groups
        foreach ($this->groups as $group) {
            foreach ($group as $variant) {
                if (mb_strtolower($variant, 'UTF-8') === $word) {
                    return $group;
                }
            }
        }

        // Pass 2: Fuzzy Levenshtein match for Latin / Hinglish terms (e.g. "bookhar" → "bukhar")
        if ($isLatin && mb_strlen($word, 'UTF-8') > 3) {
            foreach ($this->groups as $group) {
                foreach ($group as $variant) {
                    $variantLower = mb_strtolower($variant, 'UTF-8');
                    if (ctype_print($variantLower) && strlen($variantLower) < 40) {
                        $threshold = mb_strlen($variantLower, 'UTF-8') > 6 ? 2 : 1;
                        if (levenshtein($word, $variantLower) <= $threshold) {
                            return $group;
                        }
                    }
                }
            }
        }

        return [];
    }

    /**
     * Get all active synonym groups.
     * 
     * @return array<int, string[]>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }
}
