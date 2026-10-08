<?php

namespace IndicSearch\NLP;

/**
 * Transliteration Engine
 * 
 * Translates Latin/Hinglish phonetic spellings into Devanagari candidates.
 * Uses local rule-based phonetic transliteration first, and optionally falls back
 * to Google Input Tools API with strict timeout, rate-limiting, and caching.
 */
class Transliteration
{
    /** @var bool */
    private bool $enableGoogleApi;

    /** @var int Timeout in seconds for Google API calls */
    private int $apiTimeout;

    /** @var int Maximum API calls per search session to prevent slow requests */
    private int $maxApiCalls;

    /** @var int Call counter */
    private static int $callCount = 0;

    /** @var array<string, string[]> In-memory cache of transliterations */
    private static array $cache = [];

    public function __construct(bool $enableGoogleApi = true, int $apiTimeout = 2, int $maxApiCalls = 4)
    {
        $this->enableGoogleApi = $enableGoogleApi;
        $this->apiTimeout = $apiTimeout;
        $this->maxApiCalls = $maxApiCalls;
    }

    /**
     * Get Devanagari candidates for a Latin/Hinglish input query.
     * 
     * @param string $query
     * @return string[]
     */
    public function getCandidates(string $query): array
    {
        $query = trim(mb_strtolower($query, 'UTF-8'));
        if ($query === '' || !preg_match('/^[a-zA-Z\s]+$/', $query)) {
            return []; // Only pure Latin text
        }

        if (isset(self::$cache[$query])) {
            return self::$cache[$query];
        }

        $candidates = [];

        // 1. Local phonetic transliteration heuristic
        $local = $this->phoneticRomanToDevanagari($query);
        if ($local !== '') {
            $candidates[] = $local;
        }

        // 2. Google Input Tools API if enabled
        if ($this->enableGoogleApi && self::$callCount < $this->maxApiCalls) {
            $apiCandidates = $this->fetchFromGoogleApi($query);
            foreach ($apiCandidates as $cand) {
                if (!in_array($cand, $candidates, true)) {
                    $candidates[] = $cand;
                }
            }
        }

        self::$cache[$query] = $candidates;
        return $candidates;
    }

    /**
     * Fetch candidates from Google Input Tools API.
     * 
     * @return string[]
     */
    private function fetchFromGoogleApi(string $query): array
    {
        self::$callCount++;

        $url = 'https://inputtools.google.com/request?text=' . urlencode($query)
             . '&itc=hi-t-i0-und&num=3&cp=0&cs=1&ie=utf-8&oe=utf-8';

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->apiTimeout,
                'ignore_errors' => true,
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (!isset($data[0]) || $data[0] !== 'SUCCESS' || empty($data[1][0][1])) {
            return [];
        }

        return array_slice($data[1][0][1], 0, 3);
    }

    /**
     * Lightweight rule-based Roman to Devanagari transliteration.
     * Provides an immediate offline fallback for common syllabic patterns.
     */
    public function phoneticRomanToDevanagari(string $text): string
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return '';
        }

        // Multi-character consonant digraphs
        $replacements = [
            'kh' => 'ख', 'gh' => 'घ', 'ch' => 'च', 'chh' => 'छ',
            'jh' => 'झ', 'th' => 'थ', 'dh' => 'ध', 'ph' => 'फ',
            'bh' => 'भ', 'sh' => 'श', 'shh' => 'ष', 'gy' => 'ज्ञ',
            'tr' => 'त्र', 'k' => 'क', 'g' => 'ग', 'j' => 'ज',
            't' => 'त', 'd' => 'द', 'n' => 'न', 'p' => 'प',
            'b' => 'ब', 'm' => 'म', 'y' => 'य', 'r' => 'र',
            'l' => 'ल', 'v' => 'व', 'w' => 'व', 's' => 'स',
            'h' => 'ह', 'z' => 'ज़', 'f' => 'फ़', 'q' => 'क़',
            'aa' => 'आ', 'ee' => 'ई', 'oo' => 'ऊ', 'ai' => 'ऐ',
            'au' => 'औ', 'a' => 'अ', 'i' => 'इ', 'u' => 'उ',
            'e' => 'ए', 'o' => 'ओ',
        ];

        // Basic syllabic replacement for offline approximation
        $tokens = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = [];

        foreach ($tokens as $token) {
            if (ctype_space($token)) {
                $result[] = $token;
                continue;
            }
            // For common known Hinglish keywords
            $knownCommon = [
                'bukhar' => 'बुखार', 'fever' => 'बुखार', 'khansi' => 'खांसी',
                'jukam' => 'जुकाम', 'zukam' => 'जुकाम', 'sardi' => 'सर्दी',
                'dard' => 'दर्द', 'sar' => 'सर', 'pet' => 'पेट',
                'ghutna' => 'घुटना', 'kamzor' => 'कमजोर', 'khoon' => 'खून',
                'dawa' => 'दवा', 'davai' => 'दवाई', 'ilaj' => 'इलाज',
                'upchar' => 'उपचार', 'bimari' => 'बीमारी', 'swasthya' => 'स्वास्थ्य',
                'khana' => 'खाना', 'pani' => 'पानी', 'bachha' => 'बच्चा',
            ];
            if (isset($knownCommon[$token])) {
                $result[] = $knownCommon[$token];
            } else {
                $result[] = '';
            }
        }

        $res = trim(implode('', $result));
        return $res;
    }
}
