<?php

require_once __DIR__ . '/../src/IndicSearch.php';
require_once __DIR__ . '/../src/Indexer.php';
require_once __DIR__ . '/../src/Searcher.php';
require_once __DIR__ . '/../src/Tokenizer.php';
require_once __DIR__ . '/../src/NLP/Devanagari.php';
require_once __DIR__ . '/../src/NLP/Skeleton.php';
require_once __DIR__ . '/../src/NLP/Transliteration.php';
require_once __DIR__ . '/../src/Dictionary/Stopwords.php';
require_once __DIR__ . '/../src/Dictionary/Synonyms.php';
require_once __DIR__ . '/../src/Storage/Schema.php';
require_once __DIR__ . '/../src/Highlighting/Highlighter.php';

use IndicSearch\IndicSearch;

echo "=== IndicSearch Full Integration Test (MySQL) ===\n\n";

$dsn = "mysql:host=127.0.0.1;dbname=indic_search_test;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\Exception $e) {
    echo "Could not connect to MySQL: " . $e->getMessage() . "\n";
    exit(1);
}

$engine = new IndicSearch($pdo, [
    'table_prefix' => 'test_search_',
    'enable_google_transliteration' => true,
]);

// 1. Install Schema
$engine->install();
$engine->clearIndex();
echo "[PASS] Schema installed and index cleared.\n";

// 2. Index Sample Documents
$sampleDocs = [
    [
        'id' => 1,
        'title' => 'बुखार और सिरदर्द के घरेलू उपचार',
        'description' => 'मौसम बदलने पर होने वाले तेज बुखार और सिर के दर्द के लिए सरल और सुरक्षित घरेलू उपाय।',
        'body' => 'जब भी मौसम बदलता है, वायरल बुखार (viral fever) और सिरदर्द की शिकायत आम हो जाती है। खूब पानी पिएं और आराम करें।'
    ],
    [
        'id' => 2,
        'title' => 'खांसी और जुकाम से तुरंत राहत के तरीके',
        'description' => 'गले में खराश, सूखी खांसी और सर्दी जुकाम के लिए असरदार नुस्खे।',
        'body' => 'बदलते मौसम में जुकाम और खांसी की समस्या परेशान कर सकती है। अदरक और शहद का सेवन करें।'
    ],
    [
        'id' => 3,
        'title' => 'घुटनों और जोड़ों के दर्द का आयुर्वेदिक समाधान',
        'description' => 'उम्र बढ़ने के साथ घुटने के दर्द और गठिया की समस्या से कैसे पाएं राहत।',
        'body' => 'जोड़ों में अकड़न और चलने में दर्द होने पर नियमित व्यायाम और गर्म तेल की मालिश फायदेमंद होती है।'
    ],
    [
        'id' => 4,
        'title' => 'स्मार्टफोन और कंप्यूटर की नवीनतम टेक्नोलॉजी',
        'description' => 'सैमसंग और एप्पल के नए मोबाइल फोन की तुलना और फीचर्स।',
        'body' => 'आजकल 5G स्मार्टफोन और तेज कंप्यूटर की मांग तेजी से बढ़ रही है। ऑनलाइन बेस्ट कीमत पर खरीदारी करें।'
    ]
];

$indexedCount = $engine->indexBatch($sampleDocs);
echo "[PASS] Batch indexed $indexedCount sample documents.\n\n";

function runTestQuery($engine, $query, $expectedDocId, $testTitle, $options = []) {
    $res = $engine->search($query, 5, $options);
    $topDocId = $res['results'][0]['id'] ?? null;
    $hit = ($topDocId === $expectedDocId);
    $status = $hit ? "[PASS]" : "[FAIL]";
    echo " $status $testTitle: Query '$query' -> Top Result Doc ID: " . ($topDocId ?? 'None') . " (Expected: $expectedDocId)\n";
    if (!$hit) {
        echo "   Details: total=" . $res['total'] . ", terms=" . json_encode($res['terms'], JSON_UNESCAPED_UNICODE) . "\n";
    }
    return $hit;
}

$allOk = true;

// A. Exact Devanagari Search
$allOk &= runTestQuery($engine, "बुखार", 1, "Exact Devanagari Match");

// B. Typo Tolerance 1 (Matra Typo: बुखर -> बुखार)
$allOk &= runTestQuery($engine, "बुखर", 1, "Typo Tolerance (बुखर -> बुखार)");

// C. Typo Tolerance 2 (Anusvara Typo: खासी -> खांसी)
$allOk &= runTestQuery($engine, "खासी", 2, "Typo Tolerance (खासी -> खांसी)");

// D. Typo Tolerance 3 (Mahaprana Typo: जुखाम -> जुकाम)
$allOk &= runTestQuery($engine, "जुखाम", 2, "Typo Tolerance (जुखाम -> जुकाम)");

// E. Hinglish / Transliteration Search (bukhar -> बुखार)
$allOk &= runTestQuery($engine, "bukhar", 1, "Hinglish Transliteration (bukhar -> बुखार)");

// F. English Synonym Search (cough -> खांसी)
$allOk &= runTestQuery($engine, "cough", 2, "English Synonym (cough -> खांसी)");

// G. Multi-word Natural Query with Softwords (बच्चों में बुखार के लिए क्या करें)
$allOk &= runTestQuery($engine, "मौसम में तेज बुखार और सिरदर्द", 1, "Multi-word Natural Query with Softwords");

// H. Autocomplete Suggestions
$suggestions = $engine->suggest("बुख", 5);
$hasSug = !empty($suggestions);
echo ($hasSug ? " [PASS]" : " [FAIL]") . " Autocomplete Suggestion: Prefix 'बुख' -> " . json_encode($suggestions, JSON_UNESCAPED_UNICODE) . "\n";
$allOk &= $hasSug;

// I. Highlighting and Snippet
$sampleRes = $engine->search("बुखार", 1);
$snippet = $engine->snippet($sampleDocs[0]['body'], $sampleRes['terms']);
$hasHighlight = strpos($snippet, 'indic-highlight') !== false;
echo ($hasHighlight ? " [PASS]" : " [FAIL]") . " Search Snippet Highlighting: " . $snippet . "\n";
$allOk &= $hasHighlight;

echo "\n----------------------------------------\n";
echo "Integration Test Result: " . ($allOk ? "ALL TESTS PASSED SUCCESSFULLY!" : "SOME TESTS FAILED!") . "\n";
exit($allOk ? 0 : 1);
