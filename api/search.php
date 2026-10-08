<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

mb_internal_encoding("UTF-8");
mb_regex_encoding("UTF-8");

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

try {
    // Database credentials (can be overridden via environment variables)
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $dbname = getenv('DB_NAME') ?: 'indic_search_test';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $prefix = getenv('DB_PREFIX') ?: 'indic_search_';

    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Parse Input (GET or JSON POST)
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = file_get_contents('php://input');
        $input = json_decode($body, true) ?? [];
    }

    $query = trim((string) ($_GET['q'] ?? $input['query'] ?? $input['q'] ?? ''));
    $limit = max(1, min(50, intval($_GET['limit'] ?? $input['limit'] ?? 10)));
    $asYouType = !empty($_GET['as_you_type']) || !empty($input['as_you_type']);
    $noFuzzy = !empty($_GET['no_fuzzy']) || !empty($input['no_fuzzy']);

    if ($query === '') {
        echo json_encode([
            'success' => true,
            'query'   => '',
            'total'   => 0,
            'results' => [],
        ]);
        exit();
    }

    $engine = new IndicSearch($pdo, [
        'table_prefix' => $prefix,
        'enable_google_transliteration' => true,
    ]);

    $response = $engine->search($query, $limit, [
        'as_you_type' => $asYouType,
        'no_fuzzy'    => $noFuzzy,
    ]);

    echo json_encode([
        'success'      => true,
        'query'        => $response['query'],
        'total'        => $response['total'],
        'count'        => count($response['results']),
        'results'      => $response['results'],
        'terms'        => $response['terms'],
        'relaxed'      => $response['relaxed'],
        'did_you_mean' => $response['did_you_mean'],
        'suggestions'  => $response['suggestions'] ?? [],
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}
