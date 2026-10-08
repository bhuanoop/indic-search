<?php

namespace IndicSearch\NLP;

/**
 * Phonetic & Structural Skeleton Generator
 * 
 * Creates a normalized structural "skeleton" (consonant backbone) of words.
 * This allows O(1) indexed SQL lookup (`WHERE skel = ?` or `WHERE skel LIKE ?`)
 * for typo tolerance, eliminating the need for slow O(N) full-vocabulary Levenshtein scans.
 */
class Skeleton
{
    /**
     * Generate structural skeleton key for a word (Devanagari or Latin).
     * 
     * Examples:
     * - बुखार → बकर, बूखार → बकर, बुखर → बकर
     * - खांसी → कस, खासी → कस, खाँसी → कस
     * - जुकाम → जकम, जुखाम → जकम
     * - aconite → knt, ekonite → knt
     */
    public static function generate(string $norm): string
    {
        $norm = trim($norm);
        if ($norm === '') {
            return '';
        }

        static $hiFold = null;
        if ($hiFold === null) {
            $hiFold = [];
            $groups = [
                'क' => ['क', 'ख'],
                'ग' => ['ग', 'घ'],
                'च' => ['च', 'छ'],
                'ज' => ['ज', 'झ'],
                'ट' => ['ट', 'ठ'],
                'ड' => ['ड', 'ढ'],
                'त' => ['त', 'थ'],
                'द' => ['द', 'ध'],
                'प' => ['प', 'फ'],
                'ब' => ['ब', 'भ', 'व'],
                'स' => ['स', 'श', 'ष'],
                'न' => ['न', 'ण', 'ङ', 'ञ'],
                'ल' => ['ल', 'ळ'],
                // Map all independent vowels to canonical 'अ'
                'अ' => ['अ', 'आ', 'इ', 'ई', 'उ', 'ऊ', 'ऋ', 'ए', 'ऐ', 'ओ', 'औ'],
            ];
            foreach ($groups as $rep => $members) {
                foreach ($members as $ch) {
                    $hiFold[$ch] = $rep;
                }
            }
        }

        // Devanagari script processing
        if (Devanagari::hasDevanagari($norm)) {
            $clusters = Devanagari::splitClusters($norm);
            $out = '';
            foreach ($clusters as $cluster) {
                $base = mb_substr($cluster, 0, 1, 'UTF-8'); // Matras are automatically stripped
                $out .= $hiFold[$base] ?? $base;
            }
            return mb_substr($out, 0, 48, 'UTF-8');
        }

        // Latin / Hinglish processing:
        // 1. Lowercase
        // 2. Phonetic folding: ph->f, c->k, z->s, q->k, x->ks, w->v
        // 3. Deduplicate consecutive identical characters
        // 4. Remove all vowels (a, e, i, o, u, y)
        $s = strtolower($norm);
        $s = str_replace(
            ['ph', 'c', 'z', 'q', 'x', 'w'],
            ['f',  'k', 's', 'k', 'ks', 'v'],
            $s
        );
        $s = preg_replace('/(.)\1+/', '$1', $s);
        $s = preg_replace('/[aeiouy]/', '', $s);

        return substr($s, 0, 48);
    }
}
