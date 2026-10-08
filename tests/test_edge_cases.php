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

echo "=== IndicSearch Edge Cases & Robustness Test ===\n\n";

$dsn = "mysql:host=127.0.0.1;dbname=indic_search_test;charset=utf8mb4";
$pdo = new PDO($dsn, "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$engine = new IndicSearch($pdo, [
    'table_prefix' => 'edge_search_',
]);
$engine->install();
$engine->clearIndex();

$passed = 0;
$failed = 0;

function assertEdge(bool $cond, string $msg) {
    global $passed, $failed;
    if ($cond) {
        echo " [PASS] $msg\n";
        $passed++;
    } else {
        echo " [FAIL] $msg\n";
        $failed++;
    }
}

// 1. Indexing edge case: document with missing/null fields and HTML tags
try {
    $ok = $engine->index(1001, [
        'title' => 'सुरक्षित <b>HTML</b> टेस्ट <script>alert("xss")</script>',
        // description missing
        // headings missing
        'body'  => 'यह केवल एक टेस्ट है।'
    ]);
    assertEdge($ok, "Handles document with missing fields & HTML tags without error");
} catch (\Throwable $e) {
    assertEdge(false, "Failed on missing fields: " . $e->getMessage());
}

// 2. Empty query
$resEmpty = $engine->search("");
assertEdge($resEmpty['total'] === 0 && empty($resEmpty['results']), "Empty query returns 0 hits safely");

// 3. Punctuation-only query
$resPunct = $engine->search("!@#$%^&*()_+=-[]{}|;:',.<>?/`~");
assertEdge($resPunct['total'] === 0, "Punctuation-only query handled safely");

// 4. Single-character query
$resSingle = $engine->search("क");
assertEdge($resSingle['total'] === 0, "Single-character query handled safely without crash");

// 5. Extremely long query (overflow / DOS attack attempt)
$longQuery = str_repeat("बुखार ", 200); // 1400+ characters
$resLong = $engine->search($longQuery);
assertEdge(isset($resLong['total']), "1400+ character query clamped and processed safely");

// 6. SQL Injection attempts
$sqliQueries = [
    "' OR '1'='1",
    "1; DROP TABLE edge_search_term; --",
    "' UNION SELECT 1, 'test', 3, 4 --",
    "\" OR 1=1 #"
];
foreach ($sqliQueries as $sqli) {
    $resSqli = $engine->search($sqli);
    assertEdge(isset($resSqli['total']), "SQLi payload '$sqli' safely executed via PDO");
}

// 7. Stopwords-only query
$resStop = $engine->search("में और या था");
assertEdge(isset($resStop['total']), "Stopwords-only query handled smoothly");

// 8. Document Deletion
$delOk = $engine->delete(1001);
assertEdge($delOk, "Document deletion executed successfully");

$resAfterDel = $engine->search("सुरक्षित");
assertEdge($resAfterDel['total'] === 0, "Deleted document no longer appears in search results");

// 9. XSS Prevention in Highlighter
$xssInput = 'सैमसंग <script>alert("xss")</script> फोन';
$highlighted = $engine->highlight($xssInput, ["फोन"]);
assertEdge(strpos($highlighted, '<script>') === false && strpos($highlighted, '&lt;script&gt;') !== false, "Highlighter escapes dangerous HTML tags");

echo "\n----------------------------------------\n";
echo "Edge Cases Completed: $passed Passed, $failed Failed.\n";
exit($failed > 0 ? 1 : 0);
