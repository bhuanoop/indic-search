# 🇮🇳 IndicSearch

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![Powered by Think Homeo](https://img.shields.io/badge/Powered%20By-ThinkHomeo.in-blue.svg)](https://thinkhomeo.in)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

**IndicSearch** is a fast, lightweight, and production-grade **Multilingual & Indic Search Engine** for PHP & MySQL/MariaDB. It is specifically engineered to solve the complex challenges of searching in **Hindi (हिन्दी / देवनागरी)**, **Hinglish (हिंग्लिश)**, and **English** with advanced **Typo-Tolerance**, **Phonetic Skeleton Hashing**, and **BM25F Multi-Field Ranking**.

> Originally created for and battle-tested on **[Think Homeo (thinkhomeo.in)](https://thinkhomeo.in)** — *स्वस्थ जीवन की समझ*.

---

## 🌐 Live Production Demo (लाइव डेमो)

See IndicSearch running live in production at:  
👉 **[https://thinkhomeo.in](https://thinkhomeo.in)**

Try searching on the site in **Hindi** (*"बुखार"*), **Hinglish** (*"bukhar"*, *"khansi"*, *"sardard"*), or with **Typos** (*"बुखर"*, *"खासी"*, *"जुखाम"*).

---

## 🌟 Why IndicSearch? (यह क्यों आवश्यक है?)

Standard search engines (like MySQL `FULLTEXT` or basic `LIKE` queries) fail drastically on Indian languages:
1. **Devanagari Multi-byte Nature**: Standard Levenshtein counts *bytes* instead of *aksharas*, breaking Devanagari matras and conjuncts.
2. **Matra & Anusvara Typos**: Typing "खांसी" vs "खासी" or "बुखार" vs "बुखर" returns 0 hits in traditional systems.
3. **Aspirated / Mahaprana Consonants**: Hindi users routinely interchange "क/ख" (जुकाम/जुखाम) or "स/श/ष".
4. **Hinglish & Transliteration**: Over 70% of Indian internet users search in Romanized Hinglish ("bukhar", "khansi", "sardard").
5. **Slow Full-Table Scans**: Running fuzzy Levenshtein across thousands of rows causes CPU spikes and timeouts.

**IndicSearch fixes all of this** without requiring external heavyweight services like Elasticsearch or Solr — it runs on standard shared hosting (cPanel / Apache / Nginx / PHP + MySQL)!

---

## 🚀 Key Features (प्रमुख विशेषताएँ)

* **⚡ O(1) Phonetic Skeleton Hashing**: Reduces typo candidate lookups to indexed SQL lookups (`WHERE skel = ?`), eliminating slow full-table scans.
* **🎯 Devanagari Akshara Weighted Distance**: Measures edit-distance based on actual phonetic penalty:
  * Anusvara variation (खांसी ↔ खासी): Cost `0.25`
  * Similar base consonant (जुकाम ↔ जुखाम): Cost `0.35`
  * Matra variation (बुखार ↔ बुखर): Cost `0.40`
* **🔤 Hinglish & Transliteration Support**: Automatically maps Romanized Hinglish ("bukhar" → "बुखार", "khansi" → "खांसी") using local phonetic heuristics and optional Google Input Tools API.
* **📊 BM25F Multi-Field Ranking**: Scores titles, descriptions, headings, and body content with length normalization and title match boosting.
* **🔄 Dynamic Query Relaxation (Algolia-Style)**: If a strict AND query yields 0 results on long natural language questions (e.g. *"बच्चों में रात को होने वाला तेज बुखार"*), the least significant soft-words are dropped iteratively so users never see an empty screen.
* **💡 "Did You Mean" (क्या आप यह खोजना चाहते थे?)**: Distinguishes between spelling corrections (`correct`) and rare term disambiguation (`narrow`).
* **🔎 Instant As-You-Type Autocomplete**: Sub-10ms prefix completions as the user types.
* **🎨 Drop-in Frontend UI Widget**: Includes zero-dependency Vanilla JS (`indic-search.js`) with attribution and modern CSS (`indic-search.css`).

---

## 📦 Installation

### Option 1: Via Composer (Recommended)
```bash
composer require indic-search/indic-search
```

### Option 2: Manual Include
Clone or copy the `src/` folder into your project:
```php
require_once __DIR__ . '/path/to/indic-search/src/IndicSearch.php';
```

---

## ⚡ Quickstart

### 1. Initialize Engine & Install Schema
```php
use IndicSearch\IndicSearch;

$pdo = new PDO("mysql:host=localhost;dbname=my_database;charset=utf8mb4", "username", "password", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$engine = new IndicSearch($pdo, [
    'table_prefix' => 'indic_search_', // Custom table prefix
    'enable_google_transliteration' => true,
]);

// Automatically creates inverted index tables
$engine->install();
```

### 2. Index Documents
You can index articles, blog posts, e-commerce products, or news items:
```php
// Index a single document
$engine->index(1, [
    'title'       => 'बुखार और सिरदर्द के घरेलू उपचार',
    'description' => 'मौसम बदलने पर तेज बुखार और सिर के दर्द के लिए सरल उपाय।',
    'body'        => 'वायरल बुखार (viral fever) होने पर खूब पानी पिएं और आराम करें...'
]);

// Or batch index multiple documents
$engine->indexBatch([
    [
        'id'          => 2,
        'title'       => 'सैमसंग गैलेक्सी 5G स्मार्टफोन और मोबाइल फीचर्स',
        'description' => 'ऑनलाइन बेस्ट कीमत पर खरीदें नए स्मार्टफोन।',
        'body'        => 'शानदार डिस्प्ले और 5G कनेक्टिविटी...'
    ]
]);
```

### 3. Search With Typo-Tolerance
```php
// Search handles Hindi, Hinglish, English and Typos automatically!
$response = $engine->search('bukhar', 10);

echo "Total Found: " . $response['total'] . "\n";
foreach ($response['results'] as $item) {
    echo "Doc ID: " . $item['id'] . " | BM25F Score: " . $item['score'] . "\n";
}

// "Did you mean" recommendations
if (!empty($response['did_you_mean'])) {
    echo "Did you mean: " . $response['did_you_mean'][0]['alt'] . "\n";
}

// Highlighting snippets
$snippet = $engine->snippet($fullBodyText, $response['terms']);
echo $snippet;
```

---

## 🌐 Drop-In Frontend UI Widget

IndicSearch includes an accessible Vanilla JS component that works in any framework (Blade, Twig, WordPress, Plain HTML).

```html
<link rel="stylesheet" href="assets/css/indic-search.css">

<div id="indic-search-container" class="indic-search-container">
    <input type="text" id="indic-search-input" class="indic-search-input" placeholder="यहाँ खोजें...">
</div>

<script src="assets/js/indic-search.js"></script>
<script>
    new IndicSearchWidget({
        inputSelector: '#indic-search-input',
        containerSelector: '#indic-search-container',
        apiEndpoint: 'api/search.php',
        showAttribution: true, // Displays 'Search powered by Think Homeo'
        onSelect: (item) => {
            window.location.href = '/post.php?id=' + item.id;
        }
    });
</script>
```

---

## 🧪 Testing

Run the included test suites to verify NLP and database integration:

```bash
# 1. Test NLP, Devanagari Akshara Clusters, Weighted Distance & Stemming
php tests/test_nlp.php

# 2. Test Full MySQL Integration, Typo-Tolerance & Ranking
php tests/test_integration.php
```

---

## 🎗️ Attribution & Credits

IndicSearch was initially conceptualized and engineered for **[Think Homeo (thinkhomeo.in)](https://thinkhomeo.in)** to provide fast, typo-tolerant, bilingual search for health and wellness content in Hindi and English.

If you use IndicSearch in your website, web application, or academic research, please provide attribution by linking back to:
> **[Think Homeo (https://thinkhomeo.in)](https://thinkhomeo.in)** — *स्वस्थ जीवन की समझ*

---

## 📄 License

This project is open-source software licensed under the [MIT License](LICENSE).  
Copyright (c) 2026 **Think Homeo** ([https://thinkhomeo.in](https://thinkhomeo.in)).
