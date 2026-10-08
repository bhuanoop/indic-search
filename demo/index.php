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

// Database connection
$host = getenv('DB_HOST') ?: '127.0.0.1';
$dbname = getenv('DB_NAME') ?: 'indic_search_test';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$prefix = getenv('DB_PREFIX') ?: 'indic_demo_';

$query = trim($_GET['q'] ?? '');
$results = null;

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $engine = new IndicSearch($pdo, [
        'table_prefix' => $prefix,
        'enable_google_transliteration' => true,
    ]);

    // Load original sample docs lookup by ID for hydration
    $sampleDocsRaw = json_decode(file_get_contents(__DIR__ . '/sample_data.json'), true);
    $docsById = [];
    foreach ($sampleDocsRaw as $d) {
        $docsById[$d['id']] = $d;
    }

    if ($query !== '') {
        $res = $engine->search($query, 10, [
            'as_you_type' => false,
            'no_fuzzy' => !empty($_GET['no_fuzzy']),
        ]);

        $hydrated = [];
        foreach ($res['results'] as $r) {
            $docId = $r['id'];
            if (isset($docsById[$docId])) {
                $doc = $docsById[$docId];
                $doc['score'] = $r['score'];
                $doc['highlighted_title'] = $engine->highlight($doc['title'], $res['terms']);
                $doc['highlighted_description'] = $engine->highlight($doc['description'], $res['terms']);
                $doc['snippet'] = $engine->snippet($doc['body'], $res['terms'], 180);
                $hydrated[] = $doc;
            }
        }
        $results = [
            'query' => $query,
            'total' => $res['total'],
            'terms' => $res['terms'],
            'did_you_mean' => $res['did_you_mean'],
            'relaxed' => $res['relaxed'],
            'items' => $hydrated,
        ];
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IndicSearch — Multilingual Indic Search Engine</title>
    <link rel="stylesheet" href="../assets/css/indic-search.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 32px;
        }
        .header h1 {
            font-size: 2.2rem;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .header p {
            color: #64748b;
            font-size: 1.1rem;
        }
        .search-wrapper {
            margin: 0 auto 24px auto;
            max-width: 650px;
        }
        .quick-tags {
            text-align: center;
            margin-top: 14px;
            font-size: 0.9rem;
            color: #64748b;
        }
        .tag-pill {
            display: inline-block;
            background: #e2e8f0;
            color: #334155;
            padding: 4px 10px;
            border-radius: 9999px;
            text-decoration: none;
            margin: 2px 4px;
            transition: all 0.15s;
        }
        .tag-pill:hover {
            background: #2563eb;
            color: white;
        }
        .tag-pill.typo {
            border: 1px dashed #ef4444;
            color: #b91c1c;
            background: #fee2e2;
        }
        .results-box {
            margin-top: 36px;
        }
        .result-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .result-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1d4ed8;
            margin-bottom: 6px;
        }
        .result-desc {
            font-size: 0.95rem;
            color: #475569;
            margin-bottom: 8px;
        }
        .result-snippet {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            background: #f8fafc;
            padding: 10px;
            border-left: 3px solid #cbd5e1;
            border-radius: 4px;
        }
        .score-badge {
            float: right;
            font-size: 0.8rem;
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 8px;
            border-radius: 6px;
        }
        .info-pill {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #1e40af;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🇮🇳 IndicSearch</h1>
        <p>Lightweight Multilingual Search Engine with Typo-Tolerance for Hindi, Hinglish & English<br>
        <small style="color:#64748b; font-size:0.9rem;">Originally developed for <a href="https://thinkhomeo.in" target="_blank" rel="noopener" style="color:#2563eb; text-decoration:none; font-weight:600;">Think Homeo (thinkhomeo.in)</a> — स्वस्थ जीवन की समझ</small></p>
    </div>

    <div class="search-wrapper">
        <form method="GET" action="index.php">
            <div id="indic-search-container" class="indic-search-container">
                <input type="text"
                       id="indic-search-input"
                       name="q"
                       class="indic-search-input"
                       placeholder="खोजें... e.g. bukhar, खासी, जुखाम, स्मार्टफोन, दर्द"
                       value="<?= htmlspecialchars($query) ?>"
                       autofocus>
            </div>
        </form>

        <div class="quick-tags">
            <strong>त्वरित टेस्ट क्वेरी:</strong>
            <a class="tag-pill" href="index.php?q=बुखार">बुखार (Exact)</a>
            <a class="tag-pill typo" href="index.php?q=बुखर">बुखर (Typo)</a>
            <a class="tag-pill typo" href="index.php?q=खासी">खासी (Typo)</a>
            <a class="tag-pill typo" href="index.php?q=जुखाम">जुखाम (Typo)</a>
            <a class="tag-pill" href="index.php?q=bukhar">bukhar (Hinglish)</a>
            <a class="tag-pill" href="index.php?q=cough">cough (English)</a>
            <a class="tag-pill" href="index.php?q=phone">phone</a>
            <a class="tag-pill" href="index.php?q=मौसम+में+तेज+बुखार">लंबी क्वेरी</a>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div style="background:#fee2e2; border:1px solid #f87171; color:#991b1b; padding:12px; border-radius:8px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($results !== null): ?>
        <div class="results-box">
            <?php if (!empty($results['did_you_mean'])): ?>
                <?php $dym = $results['did_you_mean'][0]; ?>
                <div class="info-pill">
                    <?php if ($dym['type'] === 'correct'): ?>
                        क्या आपका मतलब था: <a href="index.php?q=<?= urlencode($dym['alt']) ?>" style="font-weight:bold; color:#1d4ed8;"><?= htmlspecialchars($dym['alt']) ?></a>?
                    <?php else: ?>
                        दिखाए जा रहे परिणाम: <strong><?= htmlspecialchars($dym['alt']) ?></strong> | 
                        <a href="index.php?q=<?= urlencode($dym['original']) ?>&no_fuzzy=1" style="color:#1d4ed8;">सिर्फ "<?= htmlspecialchars($dym['original']) ?>" खोजें</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="margin-bottom:14px; color:#64748b; font-size:0.95rem;">
                <strong><?= $results['total'] ?></strong> परिणाम मिले "<em><?= htmlspecialchars($results['query']) ?></em>" के लिए
            </div>

            <?php if (empty($results['items'])): ?>
                <div class="result-card" style="text-align:center; color:#64748b;">
                    कोई परिणाम नहीं मिला। कृपया अन्य शब्द खोजें।
                </div>
            <?php else: ?>
                <?php foreach ($results['items'] as $item): ?>
                    <div class="result-card">
                        <span class="score-badge">BM25F: <?= $item['score'] ?></span>
                        <div class="result-title"><?= $item['highlighted_title'] ?></div>
                        <div class="result-desc"><?= $item['highlighted_description'] ?></div>
                        <div class="result-snippet"><?= $item['snippet'] ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="text-align:center; margin-top:40px; padding-top:20px; border-top:1px solid #e2e8f0; color:#64748b; font-size:0.9rem;">
        मूल एल्गोरिदम एवं प्रायोजक: <a href="https://thinkhomeo.in" target="_blank" rel="noopener" style="color:#2563eb; font-weight:600; text-decoration:none;">Think Homeo (thinkhomeo.in)</a> — स्वस्थ जीवन की समझ
    </div>
</div>

<script src="../assets/js/indic-search.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        new IndicSearchWidget({
            inputSelector: '#indic-search-input',
            containerSelector: '#indic-search-container',
            apiEndpoint: '../api/search.php',
            onSelect: (item) => {
                window.location.href = 'index.php?q=' + encodeURIComponent(item.title);
            }
        });
    });
</script>
</body>
</html>
