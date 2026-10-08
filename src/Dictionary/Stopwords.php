<?php

namespace IndicSearch\Dictionary;

/**
 * Stopwords & Soft Words Manager
 * 
 * Manages general Indic (Hindi/Hinglish) and English function words,
 * prepositions, auxiliary verbs, and pronouns.
 * 
 * "Soft words" are words that are non-critical in strict AND-queries,
 * helping prevent zero-results on long natural-language queries
 * (e.g. "दिल्ली में सबसे अच्छा होटल" → "में", "सबसे", "अच्छा" are soft, while "दिल्ली" and "होटल" are required).
 */
class Stopwords
{
    /** @var array<string, bool> */
    private array $softWords = [];

    public function __construct(array $customSoftWords = [])
    {
        $this->loadDefaultSoftWords();
        if (!empty($customSoftWords)) {
            $this->addSoftWords($customSoftWords);
        }
    }

    /**
     * Check if a normalized word is a soft / non-essential query term.
     */
    public function isSoftWord(string $norm): bool
    {
        return isset($this->softWords[mb_strtolower($norm, 'UTF-8')]);
    }

    /**
     * Add domain-specific soft words (e.g. "दवा", "product", "price", etc.).
     * 
     * @param string[] $words
     */
    public function addSoftWords(array $words): void
    {
        foreach ($words as $word) {
            $word = trim(mb_strtolower($word, 'UTF-8'));
            if ($word !== '') {
                $this->softWords[$word] = true;
            }
        }
    }

    /**
     * Load core grammatical prepositions, connectors, pronouns, and auxiliary verbs.
     */
    private function loadDefaultSoftWords(): void
    {
        $defaults = [
            // --- Connectors, Prepositions & Conjunctions (हिंदी, हिंग्लिश, English) ---
            'में', 'की', 'का', 'को', 'से', 'पर', 'के', 'लिए',
            'लिये', 'और', 'या', 'जो', 'तो', 'कि', 'कर', 'करके',
            'ही', 'भी', 'ने', 'तक', 'सा', 'सी', 'तब', 'अब',
            'जब', 'कब', 'यहां', 'यहाँ', 'वहां', 'वहाँ', 'जहां', 'जहाँ', 'कहां', 'कहाँ', 'इस', 'उस',
            'जिसे', 'उसे', 'जिन्हें', 'उन्होंने', 'इसने', 'उसने', 'सब', 'सारे',
            'कुल', 'बारे', 'साथ', 'बिना', 'सहित', 'अनुसार', 'तरह', 'जैसा',
            'जैसी', 'जैसे', 'द्वारा', 'वजह', 'कारण', 'कारणों', 'वाला', 'वाली',
            'वाले', 'नहीं', 'मत', 'तथा', 'एवं', 'लेकिन', 'परंतु', 'किंतु',
            'यदि', 'अगर',
            'me', 'ki', 'ka', 'ko', 'se', 'par',
            'ke', 'liye', 'aur', 'ya', 'jo', 'to', 'kar', 'hi',
            'bhi', 'ne', 'tak', 'sa', 'si', 'tab', 'ab', 'jab',
            'kab', 'yahan', 'wahan', 'vahan', 'jahan', 'kahan', 'is', 'us',
            'jise', 'use', 'jinhe', 'unhone', 'isne', 'usne', 'sab', 'sare',
            'kul', 'n', 'and', 'or', 'with', 'for', 'of', 'in',
            'on', 'at', 'to', 'by', 'from', 'about',

            // --- Verbs & Tenses (हिंदी & हिंग्लिश) ---
            'है', 'हैं', 'था', 'थी', 'थे', 'होगा', 'होगी', 'होंगे',
            'होना', 'होता', 'होती', 'होते', 'होने', 'हो', 'रहा', 'रही',
            'रहे', 'हुआ', 'हुई', 'हुए', 'करना', 'करता', 'करती', 'करते',
            'करने', 'किया', 'किए', 'लेना', 'लेता', 'लेती', 'लेते', 'लेने',
            'लिया', 'देना', 'देता', 'देती', 'देते', 'देने', 'दिया', 'दिए',
            'आना', 'आता', 'आती', 'आते', 'आने', 'आया', 'आए', 'जाना',
            'जाता', 'जाती', 'जाते', 'जाने', 'गया', 'गए', 'गई', 'रहना',
            'रहता', 'रहती', 'रहते', 'रहने', 'लगना', 'लगता', 'लगती', 'लगते',
            'लगा', 'लगे', 'लगी', 'पाना', 'पाता', 'पाती', 'पाते', 'पाया',
            'पाए', 'सकता', 'सकती', 'सकते', 'सके', 'सकें', 'सकूं', 'सकूँ', 'चाहिए',
            'चाहिये', 'पड़े', 'पड़ता', 'पड़ती', 'पडे', 'पडता', 'पडती',
            'hai', 'hain', 'tha', 'thi', 'the',
            'hoga', 'hogi', 'hoge', 'hona', 'hota', 'hoti', 'hote', 'ho',
            'raha', 'rahi', 'rahe', 'karna', 'karta', 'karti', 'karte', 'kiya',
            'kiye', 'lena', 'leta', 'leti', 'lete', 'liya', 'dena', 'deta',
            'deti', 'dete', 'diya', 'diye', 'wala', 'wali', 'wali', 'aana',
            'aata', 'aati', 'aate', 'aaya', 'aaye', 'jana', 'jata', 'jati',
            'jate', 'gaya', 'gaye', 'gayi', 'rahana', 'rahna', 'rahta', 'rahti',
            'rahte', 'lagna', 'lagta', 'lagti', 'lagte', 'laga', 'lage', 'lagi',
            'pana', 'pata', 'pati', 'pate', 'paya', 'paye', 'chahiye',
            'is', 'am', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'shall', 'should', 'can', 'could', 'may', 'might', 'must',

            // --- Quantifiers, Adverbs & General Modifiers ---
            'बहुत', 'ज्यादा', 'ज़्यादा', 'कम', 'थोड़ा', 'थोड़ी', 'थोड़े', 'थोडा', 'थोडी', 'थोडे', 'अधिक', 'बेहद',
            'काफ़ी', 'काफी', 'अक्सर', 'हमेशा', 'रोज़', 'रोज', 'प्रतिदिन', 'कभी', 'तुरंत',
            'जल्दी', 'धीरे', 'रात', 'दिन', 'सुबह', 'दोपहर', 'शाम', 'सवेरे',
            'वक्त', 'दौरान', 'समय', 'पहले', 'बाद', 'आजकल', 'पुराना', 'पुरानी',
            'पुराने', 'नया', 'नई', 'नए', 'अच्छा', 'अच्छी', 'अच्छे', 'बुरा',
            'बुरी', 'बुरे', 'मुख्य', 'खास', 'ख़ास', 'आम',
            'bahut', 'jyada', 'jiyada', 'zada', 'kam', 'thoda', 'thodi', 'thode',
            'adhik', 'behad', 'kafi', 'kaafi', 'aksar', 'hamesha', 'roj', 'roz',
            'daily', 'kabhi', 'turant', 'jaldi', 'dheere', 'raat', 'din', 'subah',
            'dopahar', 'shaam', 'aajkal', 'pahle', 'pehle', 'baad', 'bad',
            'purana', 'purani', 'purane', 'naya', 'nai', 'naye',
            'very', 'much', 'more', 'less', 'best', 'good', 'new', 'old',

            // --- Pronouns & Question Words ---
            'मेरे', 'मेरी', 'मेरा', 'मुझे', 'मुझको', 'मुझ', 'मैं', 'हम',
            'हमारे', 'हमारा', 'हमारी', 'हमें', 'हमको', 'आपका', 'आपकी', 'आपके',
            'आपको', 'आप', 'तुम', 'तुम्हें', 'तुम्हारा', 'तुम्हारी', 'तुम्हारे', 'अपने',
            'अपनी', 'अपना', 'स्वयं', 'खुद', 'उनका', 'उनकी', 'उनके', 'उन्हें',
            'उनको', 'उन', 'इसका', 'इसकी', 'इसके', 'इसे', 'इसको', 'उसका',
            'उसकी', 'उसके', 'उसे', 'उसको', 'उस', 'इनका', 'इनकी', 'इनके', 'इन्हें', 'इनको',
            'इन', 'किसी', 'किन्हीं', 'किसीको', 'क्या', 'क्यों', 'कैसे', 'कैसा',
            'कैसी', 'कब', 'किस', 'किसे', 'कितना', 'कितनी', 'कितने', 'कौन', 'कौनसा',
            'कौनसी', 'कौनसे',
            'mere', 'meri', 'mera', 'mujhe', 'mujhko', 'main', 'mai', 'hum',
            'hamare', 'hamara', 'hamari', 'hame', 'hamko', 'aapka', 'aapki', 'aapke',
            'tum', 'tumhe', 'tumhara', 'tumhari', 'tumhare', 'apne', 'apni', 'apna',
            'swayan', 'swayam', 'svayam', 'khud', 'unka', 'unki', 'unke', 'unhe',
            'unhein', 'use', 'usne', 'isne', 'jinhe', 'unhone', 'kya', 'kyon', 'kaise',
            'who', 'what', 'where', 'when', 'why', 'how', 'which', 'this', 'that', 'these', 'those'
        ];

        $this->softWords = array_fill_keys(array_map(fn($w) => mb_strtolower($w, 'UTF-8'), $defaults), true);
    }
}
