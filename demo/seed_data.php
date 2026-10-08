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

$host = getenv('DB_HOST') ?: '127.0.0.1';
$dbname = getenv('DB_NAME') ?: 'indic_search_test';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$prefix = getenv('DB_PREFIX') ?: 'indic_demo_';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $engine = new IndicSearch($pdo, [
        'table_prefix' => $prefix,
        'enable_google_transliteration' => true,
    ]);

    echo "1. Installing Database Schema...\n";
    $engine->install();
    $engine->clearIndex();

    echo "2. Reading Sample Dataset...\n";
    $jsonFile = __DIR__ . '/sample_data.json';
    $docs = json_decode(file_get_contents($jsonFile), true);

    echo "3. Indexing " . count($docs) . " documents...\n";
    $count = $engine->indexBatch($docs);

    echo "Successfully indexed {$count} documents with prefix '{$prefix}'!\n";
    echo "You can now run the web demo or test searches.\n";

} catch (\Throwable $e) {
    echo "Error seeding data: " . $e->getMessage() . "\n";
    exit(1);
}
