<?php

require_once __DIR__ . '/../src/NLP/Devanagari.php';
require_once __DIR__ . '/../src/NLP/Skeleton.php';
require_once __DIR__ . '/../src/NLP/Transliteration.php';
require_once __DIR__ . '/../src/Dictionary/Stopwords.php';
require_once __DIR__ . '/../src/Dictionary/Synonyms.php';
require_once __DIR__ . '/../src/Tokenizer.php';
require_once __DIR__ . '/../src/Highlighting/Highlighter.php';

use IndicSearch\NLP\Devanagari;
use IndicSearch\NLP\Skeleton;
use IndicSearch\NLP\Transliteration;
use IndicSearch\Dictionary\Stopwords;
use IndicSearch\Dictionary\Synonyms;
use IndicSearch\Tokenizer;
use IndicSearch\Highlighting\Highlighter;

echo "=== IndicSearch NLP Unit Tests ===\n\n";

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $title) {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $title\n";
        $passed++;
    } else {
        echo " [FAIL] $title\n";
        $failed++;
    }
}

// 1. Devanagari Normalization
$norm1 = Devanagari::normalizeWord("क़लम");
assertTest($norm1 === "कलम", "Nukta normalization: क़लम -> कलम ($norm1)");

$norm2 = Devanagari::normalizeWord("हाँ");
assertTest($norm2 === "हां", "Chandrabindu to anusvara: हाँ -> हां ($norm2)");

// 2. Grapheme Clusters
$clusters = Devanagari::splitClusters("बुखार");
assertTest(count($clusters) === 3 && $clusters[0] === "बु" && $clusters[1] === "खा" && $clusters[2] === "र", "Cluster splitting: बुखार -> [बु, खा, र]");

$clustersKhansi = Devanagari::splitClusters("खांसी");
assertTest(count($clustersKhansi) === 2 && $clustersKhansi[0] === "खां" && $clustersKhansi[1] === "सी", "Cluster splitting: खांसी -> [खां, सी]");

// 3. Similar Consonants
assertTest(Devanagari::areSimilarBases("क", "ख"), "Similar base: क and ख");
assertTest(Devanagari::areSimilarBases("स", "श"), "Similar base: स and श");
assertTest(!Devanagari::areSimilarBases("क", "ग"), "Distinct base: क and ग (different meaning)");

// 4. Weighted Cluster Distance (Typo Tolerance)
$c1 = Devanagari::splitClusters("खांसी");
$c2 = Devanagari::splitClusters("खासी");
$distAnusvar = Devanagari::clusterDistance($c1, $c2);
assertTest($distAnusvar <= 0.25, "Anusvara typo cost: खांसी vs खासी = $distAnusvar (expected <= 0.25)");

$c3 = Devanagari::splitClusters("जुकाम");
$c4 = Devanagari::splitClusters("जुखाम");
$distMahapran = Devanagari::clusterDistance($c3, $c4);
assertTest($distMahapran <= 0.35, "Mahaprana typo cost: जुकाम vs जुखाम = $distMahapran (expected <= 0.35)");

$c5 = Devanagari::splitClusters("बुखार");
$c6 = Devanagari::splitClusters("बुखर");
$distMatra = Devanagari::clusterDistance($c5, $c6);
assertTest($distMatra <= 0.40, "Matra typo cost: बुखार vs बुखर = $distMatra (expected <= 0.40)");

// 5. Devanagari Stemming
$stem1 = Devanagari::stemWord("खांसियां");
assertTest($stem1 === "खांस", "Hindi suffix stem: खांसियां -> खांस ($stem1)");

$stem2 = Devanagari::stemWord("दवाइयां");
assertTest($stem2 === "दवा", "Hindi suffix stem: दवाइयां -> दवा ($stem2)");

// 6. Phonetic Skeleton
$skel1 = Skeleton::generate("बुखार");
$skel2 = Skeleton::generate("बूखार");
$skel3 = Skeleton::generate("बुखर");
assertTest($skel1 === $skel2 && $skel2 === $skel3, "Hindi skeleton equivalence: बुखार == बूखार == बुखर (skel: $skel1)");

$skel4 = Skeleton::generate("aconite");
$skel5 = Skeleton::generate("ekonite");
assertTest($skel4 === $skel5, "Latin skeleton equivalence: aconite == ekonite (skel: $skel4)");

// 7. Stopwords & Softwords
$stopwords = new Stopwords();
assertTest($stopwords->isSoftWord("में"), "Devanagari soft word: में");
assertTest($stopwords->isSoftWord("hai"), "Hinglish soft word: hai");
assertTest($stopwords->isSoftWord("for"), "English soft word: for");
assertTest(!$stopwords->isSoftWord("बुखार"), "Content word not soft: बुखार");

// 8. Synonyms Expansion
$synonyms = new Synonyms();
$synFever = $synonyms->expand("fever");
assertTest(in_array("बुखार", $synFever, true) && in_array("bukhar", $synFever, true), "Synonym expansion: fever -> bukhar, बुखार");

$synTypo = $synonyms->expand("bookhar");
assertTest(in_array("बुखार", $synTypo, true), "Synonym fuzzy expansion: bookhar -> bukhar, बुखार");

// 9. Tokenizer
$tokenData = Tokenizer::tokenize("तेज बुखार और सिरदर्द के घरेलू उपचार (Quick relief for fever)");
assertTest(isset($tokenData['tf']['बुखार']), "Tokenizer Devanagari token present");
assertTest(isset($tokenData['tf']['fever']), "Tokenizer Latin token present");
assertTest(isset($tokenData['surface']['बुखार']), "Surface term marked");

// 10. Highlighter
$text = "तेज बुखार में क्या करें और कब डॉक्टर को दिखाएं";
$highlighted = Highlighter::highlight($text, ["बुखार", "fever"]);
assertTest(strpos($highlighted, '<mark class="indic-highlight">बुखार</mark>') !== false, "Highlighter correctly tags search terms");

$snippet = Highlighter::createSnippet("यहाँ बहुत लंबा लेख है जिसके बीच में तेज बुखार का उल्लेख है और अंत में निष्कर्ष है।", ["बुखार"], 40);
assertTest(strpos($snippet, 'बुखार') !== false, "Snippet generation centers on query term");

echo "\n----------------------------------------\n";
echo "Tests Completed: $passed Passed, $failed Failed.\n";
exit($failed > 0 ? 1 : 0);
