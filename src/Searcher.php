<?php

namespace IndicSearch;

use PDO;
use IndicSearch\Storage\Schema;
use IndicSearch\NLP\Devanagari;
use IndicSearch\NLP\Skeleton;
use IndicSearch\NLP\Transliteration;
use IndicSearch\Dictionary\Stopwords;
use IndicSearch\Dictionary\Synonyms;
use IndicSearch\Highlighting\Highlighter;

/**
 * IndicSearch Searcher Engine
 * 
 * Executes full-text search with:
 * - BM25F ranking with customizable field weights
 * - Fast O(1) skeleton-indexed typo-tolerance
 * - Devanagari akshara weighted cluster distance
 * - Hinglish transliteration and phonetic expansion
 * - Multi-word AND matching with graceful IDF-based relaxation
 * - Phrase proximity boost and negative keyword handling
 * - "Did you mean" spelling corrections
 * - Instant as-you-type prefix autocomplete
 */
class Searcher
{
    private PDO $db;
    private Schema $schema;
    private Stopwords $stopwords;
    private Synonyms $synonyms;
    private Transliteration $transliteration;

    /** @var array<int, float> BM25F field weights */
    private array $fieldWeights = [
        Indexer::FIELD_TITLE => 8.0,
        Indexer::FIELD_DESC  => 3.0,
        Indexer::FIELD_HEAD  => 3.0,
        Indexer::FIELD_BODY  => 1.0,
    ];

    /** @var int Max characters in query string */
    public const MAX_QUERY_LENGTH = 200;

    public function __construct(
        PDO $db,
        Schema $schema,
        ?Stopwords $stopwords = null,
        ?Synonyms $synonyms = null,
        ?Transliteration $transliteration = null
    ) {
        $this->db = $db;
        $this->schema = $schema;
        $this->stopwords = $stopwords ?? new Stopwords();
        $this->synonyms = $synonyms ?? new Synonyms();
        $this->transliteration = $transliteration ?? new Transliteration();
    }

    /**
     * Set custom BM25F field weights.
     * 
     * @param array<int, float> $weights
     */
    public function setFieldWeights(array $weights): void
    {
        $this->fieldWeights = $weights;
    }

    /**
     * Execute a search query.
     * 
     * @param string $query User query string
     * @param int $limit Maximum results to return
     * @param array{
     *     as_you_type?: bool,
     *     no_fuzzy?: bool,
     *     fetch_fields?: string[],
     *     source?: string
     * } $options Search options
     * @return array{
     *     results: array,
     *     total: int,
     *     query: string,
     *     terms: string[],
     *     relaxed: string[],
     *     did_you_mean: array,
     *     suggestions?: string[]
     * }
     */
    public function search(string $query, int $limit = 10, array $options = []): array
    {
        $query = $this->clampQuery($query);
        if ($query === '') {
            return [
                'results'      => [],
                'total'        => 0,
                'query'        => '',
                'terms'        => [],
                'relaxed'      => [],
                'did_you_mean' => [],
            ];
        }

        $meta = $this->getIndexMeta();
        $docCount = (int) ($meta['doc_count'] ?? 0);
        if ($docCount < 1) {
            return [
                'results'      => [],
                'total'        => 0,
                'query'        => $query,
                'terms'        => [$query],
                'relaxed'      => [],
                'did_you_mean' => [],
            ];
        }

        $unknown = [];
        $groups = $this->buildQueryGroups($query, $options, $unknown);
        if (empty($groups)) {
            return [
                'results'      => [],
                'total'        => 0,
                'query'        => $query,
                'terms'        => [$query],
                'relaxed'      => $unknown,
                'did_you_mean' => [],
            ];
        }

        $this->annotateIdf($groups, $docCount);

        // Build "Did you mean" suggestions from typo and transliteration corrections
        $didYouMean = $this->buildDidYouMean($groups);

        // Retrieve postings using two-phase selective/broad strategy
        $hits = $this->fetchCandidateHits($groups, $docCount);
        if (empty($hits)) {
            return [
                'results'      => [],
                'total'        => 0,
                'query'        => $query,
                'terms'        => $this->extractHighlightTerms($groups),
                'relaxed'      => $unknown,
                'did_you_mean' => $didYouMean,
            ];
        }

        // Multi-word AND matching with graceful IDF-based relaxation
        $relaxed = [];
        $matchedDocs = $this->matchAndRelax($groups, $hits, $unknown, $relaxed);

        // BM25F ranking + Phrase proximity boost
        $ranked = $this->scoreAndRank($matchedDocs, $hits, $groups, $meta, $query);

        $totalHits = count($ranked);
        $topSlice = array_slice($ranked, 0, max(1, $limit));
        $highlightTerms = $this->extractHighlightTerms($groups);

        $response = [
            'results'      => $topSlice,
            'total'        => $totalHits,
            'query'        => $query,
            'terms'        => $highlightTerms,
            'relaxed'      => array_values(array_unique($relaxed)),
            'did_you_mean' => $didYouMean,
        ];

        if (!empty($options['as_you_type']) || $totalHits === 0 || !empty($relaxed)) {
            $response['suggestions'] = $this->suggest($query, 6);
        }

        return $response;
    }

    /**
     * Provide autocomplete as-you-type suggestions.
     * 
     * @param string $query
     * @param int $limit
     * @return string[]
     */
    public function suggest(string $query, int $limit = 6): array
    {
        $query = $this->clampQuery($query);
        $words = Tokenizer::parseQuery($query);
        if (empty($words)) {
            return [];
        }

        $lastWord = end($words);
        $prefix = $lastWord['norm'];
        if (mb_strlen($prefix, 'UTF-8') < 2) {
            return [];
        }

        $termTable = $this->schema->getTermTable();
        $like = addcslashes($prefix, '%_\\') . '%';

        $st = $this->db->prepare(
            "SELECT raw, df FROM `{$termTable}`
             WHERE term LIKE ? AND is_surface = 1
             ORDER BY df DESC LIMIT ?"
        );
        $st->bindValue(1, $like);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();

        $suggestions = [];
        $basePrefix = count($words) > 1
            ? implode(' ', array_map(fn($w) => $w['raw'], array_slice($words, 0, -1))) . ' '
            : '';

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $suggestions[] = $basePrefix . $r['raw'];
        }

        // If prefix yields few results, try skeleton prefix
        if (count($suggestions) < $limit) {
            $skel = Skeleton::generate($prefix);
            if ($skel !== '') {
                $skelLike = addcslashes($skel, '%_\\') . '%';
                $st2 = $this->db->prepare(
                    "SELECT raw, df FROM `{$termTable}`
                     WHERE skel LIKE ? AND is_surface = 1
                     ORDER BY df DESC LIMIT ?"
                );
                $st2->bindValue(1, $skelLike);
                $st2->bindValue(2, $limit * 2, PDO::PARAM_INT);
                $st2->execute();

                while ($r = $st2->fetch(PDO::FETCH_ASSOC)) {
                    $cand = $basePrefix . $r['raw'];
                    if (!in_array($cand, $suggestions, true)) {
                        $suggestions[] = $cand;
                        if (count($suggestions) >= $limit) break;
                    }
                }
            }
        }

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Clamp query to safe character length.
     */
    private function clampQuery(string $query): string
    {
        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') > self::MAX_QUERY_LENGTH) {
            $query = trim(mb_substr($query, 0, self::MAX_QUERY_LENGTH, 'UTF-8'));
        }
        return $query;
    }

    /**
     * Read index metadata (doc count and average field lengths).
     * 
     * @return array<string, mixed>
     */
    private function getIndexMeta(): array
    {
        $metaTable = $this->schema->getMetaTable();
        try {
            $st = $this->db->query("SELECT mkey, mval FROM `{$metaTable}`");
            return $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build semantic and phonetic variant groups for each query word.
     */
    private function buildQueryGroups(string $query, array $opts, array &$unknown): array
    {
        $words = Tokenizer::parseQuery($query);
        if (empty($words)) {
            return [];
        }

        $asYouType = !empty($opts['as_you_type']);
        $lastIdx = count($words) - 1;

        // Batch lookup all exact forms and Devanagari stems
        $probe = [];
        foreach ($words as $w) {
            $probe[$w['norm']] = true;
            if ($w['hi']) {
                $probe[Devanagari::stemWord($w['norm'])] = true;
            }
        }
        $known = $this->lookupTerms(array_keys($probe));

        $groups = [];
        foreach ($words as $i => $w) {
            $variants = [];
            $primary = null;

            // 1. Exact match
            if (isset($known[$w['norm']])) {
                $variants[$w['norm']] = $known[$w['norm']] + ['fuzzy' => 0];
                $primary = $w['norm'];
            }

            // 2. Light Devanagari stem
            $stem = $w['hi'] ? Devanagari::stemWord($w['norm']) : $w['norm'];
            if ($stem !== $w['norm'] && isset($known[$stem])) {
                $variants[$stem] = $known[$stem] + ['fuzzy' => 0];
                $primary = $primary ?? $stem;
            }

            $exactHit = ($primary !== null);

            // 3. Synonym dictionary expansion
            $synGroup = $this->synonyms->expand($w['raw']);
            if (!empty($synGroup)) {
                $synNorms = [];
                foreach ($synGroup as $synItem) {
                    foreach (Tokenizer::parseQuery($synItem) as $sw) {
                        $synNorms[$sw['norm']] = true;
                    }
                }
                foreach ($this->lookupTerms(array_keys($synNorms)) as $t => $row) {
                    if (!isset($variants[$t])) {
                        $variants[$t] = $row + ['fuzzy' => 0];
                    }
                }
            }

            // 4. Hinglish → Devanagari Transliteration
            $translitAdded = [];
            if (!$exactHit && !$w['hi']) {
                $candNorms = [];
                foreach ($this->transliteration->getCandidates($w['raw']) as $cand) {
                    foreach (Tokenizer::parseQuery($cand) as $cw) {
                        $candNorms[$cw['norm']] = true;
                    }
                }
                foreach ($this->lookupTerms(array_keys($candNorms)) as $t => $row) {
                    if (!isset($variants[$t])) {
                        $variants[$t] = $row + ['fuzzy' => 0];
                        $primary = $primary ?? $t;
                    }
                    $translitAdded[$t] = (int) $row['df'];
                }
            }

            // 5. As-you-type prefix matching for the final token
            if ($i === $lastIdx && ($asYouType || !$exactHit)) {
                foreach ($this->fetchPrefixTerms($w) as $t => $row) {
                    if (!isset($variants[$t])) {
                        $variants[$t] = $row;
                    }
                }
            }

            // 6. Phonetic Skeleton Typo-Tolerance
            $ownDf = isset($known[$w['norm']]) ? (int) $known[$w['norm']]['df'] : 0;
            $fuzzyAdded = [];
            if (empty($opts['no_fuzzy']) && (!$exactHit || $ownDf <= 1)) {
                foreach ($this->fetchFuzzyTerms($w) as $t => $row) {
                    if (!isset($variants[$t])) {
                        $variants[$t] = $row;
                    }
                    $fuzzyAdded[$t] = (int) $row['df'];
                }
            }

            if (empty($variants)) {
                $unknown[] = $w['raw'];
                continue;
            }

            $groups[] = [
                'label'          => $w['raw'],
                'norm'           => $w['norm'],
                'primary'        => $primary,
                'variants'       => $variants,
                'exact'          => $exactHit,
                'own_df'         => $ownDf,
                'fuzzy_added'    => $fuzzyAdded,
                'translit_added' => $translitAdded,
                'soft'           => $this->stopwords->isSoftWord($w['norm']),
            ];
        }

        return $groups;
    }

    /**
     * Batch lookup terms in search_term table.
     */
    private function lookupTerms(array $terms): array
    {
        $terms = array_values(array_unique(array_filter($terms, fn($t) => $t !== '')));
        if (empty($terms)) {
            return [];
        }

        $termTable = $this->schema->getTermTable();
        $out = [];
        foreach (array_chunk($terms, 300) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->db->prepare(
                "SELECT term_id, term, raw, df, best_field, is_surface
                 FROM `{$termTable}` WHERE term IN ($ph)"
            );
            $st->execute($chunk);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $out[$r['term']] = [
                    'id'         => (int) $r['term_id'],
                    'raw'        => $r['raw'],
                    'df'         => (int) $r['df'],
                    'best_field' => (int) $r['best_field'],
                    'is_surface' => (int) $r['is_surface'],
                ];
            }
        }

        return $out;
    }

    /**
     * Fetch prefix term candidates for incomplete as-you-type words.
     */
    private function fetchPrefixTerms(array $word, int $limit = 6): array
    {
        $out = [];
        if ($word['k'] < 2) return $out;

        $termTable = $this->schema->getTermTable();
        $like = addcslashes($word['norm'], '%_\\') . '%';

        $st = $this->db->prepare(
            "SELECT term_id, term, raw, df, best_field FROM `{$termTable}`
             WHERE term LIKE ? AND is_surface = 1 ORDER BY df DESC LIMIT ?"
        );
        $st->bindValue(1, $like);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[$r['term']] = [
                'id' => (int) $r['term_id'],
                'raw' => $r['raw'],
                'df' => (int) $r['df'],
                'best_field' => (int) $r['best_field'],
                'fuzzy' => 0
            ];
        }

        if (!empty($out)) return $out;

        // Try skeleton prefix lookup
        $skel = Skeleton::generate($word['norm']);
        if ($skel === '') return $out;

        $st = $this->db->prepare(
            "SELECT term_id, term, raw, df, best_field FROM `{$termTable}`
             WHERE skel LIKE ? AND is_surface = 1 ORDER BY df DESC LIMIT 60"
        );
        $st->execute([addcslashes($skel, '%_\\') . '%']);

        $qc = $word['hi'] ? Devanagari::splitClusters($word['norm']) : [];
        $budget = $word['k'] <= 2 ? 0.5 : 0.9;
        $scored = [];

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($word['hi']) {
                $head = array_slice(Devanagari::splitClusters($r['term']), 0, $word['k']);
                $d = Devanagari::clusterDistance($qc, $head);
            } else {
                $d = (float) levenshtein($word['norm'], substr($r['term'], 0, strlen($word['norm'])));
            }
            if ($d <= $budget) {
                $scored[] = [$d, -(int) $r['df'], $r];
            }
        }

        sort($scored);
        foreach (array_slice($scored, 0, $limit) as $s) {
            $r = $s[2];
            $out[$r['term']] = [
                'id' => (int) $r['term_id'],
                'raw' => $r['raw'],
                'df' => (int) $r['df'],
                'best_field' => (int) $r['best_field'],
                'fuzzy' => 1
            ];
        }

        return $out;
    }

    /**
     * Typo-tolerance matching:
     * Fast O(1) indexed SQL query on `skel = ?`, followed by weighted cluster edit-distance.
     */
    private function fetchFuzzyTerms(array $word, int $limit = 6): array
    {
        $out = [];
        if ($word['k'] < 2) return $out;

        $skel = Skeleton::generate($word['norm']);
        if ($skel === '') return $out;

        $termTable = $this->schema->getTermTable();
        $st = $this->db->prepare(
            "SELECT term_id, term, raw, df, best_field FROM `{$termTable}`
             WHERE skel = ? AND is_surface = 1 ORDER BY df DESC LIMIT 60"
        );
        $st->execute([$skel]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return $out;

        $selfDf = 0;
        foreach ($rows as $r) {
            if ($r['term'] === $word['norm']) {
                $selfDf = (int) $r['df'];
            }
        }

        $minDf = max(1, $selfDf * 2);
        $budget = $this->getTypoBudget($word['k']);
        $qc = $word['hi'] ? Devanagari::splitClusters($word['norm']) : [];

        $scored = [];
        foreach ($rows as $r) {
            if ($r['term'] === $word['norm'] || (int) $r['df'] < $minDf) {
                continue;
            }
            $d = $word['hi']
                ? Devanagari::clusterDistance($qc, Devanagari::splitClusters($r['term']))
                : (float) levenshtein($word['norm'], $r['term']);

            if ($d <= $budget) {
                $scored[] = [$d, -(int) $r['df'], $r];
            }
        }

        if (empty($scored)) return $out;

        sort($scored);
        $best = $scored[0][0] + 0.001;

        foreach (array_slice($scored, 0, $limit) as $s) {
            if ($s[0] > $best) break;
            $r = $s[2];
            $out[$r['term']] = [
                'id' => (int) $r['term_id'],
                'raw' => $r['raw'],
                'df' => (int) $r['df'],
                'best_field' => (int) $r['best_field'],
                'fuzzy' => 1
            ];
        }

        return $out;
    }

    /**
     * Adaptive typo tolerance budget based on token length.
     */
    private function getTypoBudget(int $k): float
    {
        if ($k <= 2) return 0.5; // Single matra or anusvara variance
        if ($k === 3) return 0.8;
        if ($k <= 5) return 1.2;
        return 2.0;
    }

    /**
     * Annotate BM25 standard IDF for all variant terms.
     */
    private function annotateIdf(array &$groups, int $docCount): void
    {
        $N = max(1, $docCount);
        foreach ($groups as &$g) {
            $sumDf = 0;
            $maxIdf = 0.0;
            foreach ($g['variants'] as $t => $v) {
                $df = max(1, min($N, (int) $v['df']));
                $sumDf += $df;
                $idf = log(1 + ($N - $df + 0.5) / ($df + 0.5));
                $g['variants'][$t]['idf'] = $idf;
                $maxIdf = max($maxIdf, $idf);
            }
            $g['df'] = min($N, $sumDf);
            $g['idf'] = ($g['primary'] !== null && isset($g['variants'][$g['primary']]['idf']))
                ? $g['variants'][$g['primary']]['idf']
                : $maxIdf;
        }
        unset($g);
    }

    /**
     * Build 'Did you mean' spelling recommendations.
     */
    private function buildDidYouMean(array $groups): array
    {
        $didYouMean = [];
        foreach ($groups as $g) {
            // Fuzzy correction
            if (!empty($g['fuzzy_added'])) {
                $fa = $g['fuzzy_added'];
                arsort($fa);
                $bestFuzzy = array_key_first($fa);
                if ($bestFuzzy !== null && $bestFuzzy !== $g['norm']) {
                    $didYouMean[] = [
                        'type'     => $g['exact'] ? 'narrow' : 'correct',
                        'original' => $g['label'],
                        'alt'      => $bestFuzzy,
                    ];
                }
            }

            // Transliteration correction (Latin → Devanagari)
            if (!empty($g['translit_added'])) {
                $ta = $g['translit_added'];
                arsort($ta);
                $bestTr = array_key_first($ta);
                if ($bestTr !== null) {
                    $didYouMean[] = [
                        'type'     => 'correct',
                        'original' => $g['label'],
                        'alt'      => $bestTr,
                    ];
                }
            }
        }

        return $didYouMean;
    }

    /**
     * Fetch postings using a two-stage selective/broad query strategy.
     */
    private function fetchCandidateHits(array $groups, int $docCount): array
    {
        $broadCut = max(50, (int) round(0.30 * $docCount));
        $selective = [];
        $broad = [];

        foreach ($groups as $gi => $g) {
            if ($g['df'] <= $broadCut) {
                $selective[] = $gi;
            } else {
                $broad[] = $gi;
            }
        }

        if (empty($selective)) {
            $minGi = 0;
            foreach ($groups as $gi => $g) {
                if ($g['df'] < $groups[$minGi]['df']) {
                    $minGi = $gi;
                }
            }
            $selective = [$minGi];
            $broad = array_values(array_diff(array_keys($groups), $selective));
        }

        $termGroup = [];
        foreach ($groups as $gi => $g) {
            foreach ($g['variants'] as $v) {
                $termGroup[$v['id']] = $gi;
            }
        }

        $hits = [];
        $collect = function ($rows) use (&$hits, $termGroup) {
            foreach ($rows as $r) {
                $tid = (int) $r['term_id'];
                if (!isset($termGroup[$tid])) continue;
                $hits[(int) $r['doc_id']][$termGroup[$tid]][$tid][(int) $r['field']] = (int) $r['tf'];
            }
        };

        $selTermIds = [];
        foreach ($selective as $gi) {
            foreach ($groups[$gi]['variants'] as $v) {
                $selTermIds[] = $v['id'];
            }
        }
        $collect($this->fetchPostings($selTermIds));

        if (!empty($broad) && !empty($hits)) {
            $broadTermIds = [];
            foreach ($broad as $gi) {
                foreach ($groups[$gi]['variants'] as $v) {
                    $broadTermIds[] = $v['id'];
                }
            }
            $collect($this->fetchPostings($broadTermIds, array_keys($hits)));
        }

        return $hits;
    }

    /**
     * Query posting rows from search_posting table.
     */
    private function fetchPostings(array $termIds, ?array $restrictDocs = null): array
    {
        $termIds = array_values(array_unique(array_map('intval', $termIds)));
        if (empty($termIds)) return [];

        $postingTable = $this->schema->getPostingTable();
        $out = [];

        foreach (array_chunk($termIds, 300) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            if ($restrictDocs !== null) {
                if (empty($restrictDocs)) continue;
                $rIds = array_values(array_unique(array_map('intval', $restrictDocs)));
                foreach (array_chunk($rIds, 500) as $rChunk) {
                    $rph = implode(',', array_fill(0, count($rChunk), '?'));
                    $st = $this->db->prepare(
                        "SELECT term_id, doc_id, field, tf FROM `{$postingTable}`
                         WHERE term_id IN ($ph) AND doc_id IN ($rph)"
                    );
                    $st->execute(array_merge($chunk, $rChunk));
                    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                        $out[] = $r;
                    }
                }
            } else {
                $st = $this->db->prepare(
                    "SELECT term_id, doc_id, field, tf FROM `{$postingTable}` WHERE term_id IN ($ph)"
                );
                $st->execute($chunk);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $out[] = $r;
                }
            }
        }

        return $out;
    }

    /**
     * Strict AND matching with graceful IDF-based query relaxation.
     */
    private function matchAndRelax(array $groups, array $hits, array $unknown, array &$relaxed): array
    {
        $order = array_keys($groups);
        usort($order, fn($a, $b) => $groups[$b]['idf'] <=> $groups[$a]['idf']);

        $nonSoft = array_values(array_filter($order, fn($gi) => empty($groups[$gi]['soft'])));
        $required = !empty($nonSoft) ? $nonSoft : [$order[0]];
        $relaxed = $unknown;

        $matched = [];
        while (true) {
            $matched = [];
            foreach ($hits as $docId => $byGroup) {
                $ok = true;
                foreach ($required as $gi) {
                    if (!isset($byGroup[$gi])) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $matched[$docId] = true;
                }
            }

            if (!empty($matched) || count($required) <= 1) {
                break;
            }

            // Drop the least informative required word
            $dropped = array_pop($required);
            $relaxed[] = $groups[$dropped]['label'];
        }

        if (empty($matched)) {
            $matched = array_fill_keys(array_keys($hits), true);
        }

        return $matched;
    }

    /**
     * BM25F scoring and ranking.
     */
    private function scoreAndRank(array $matchedDocs, array $hits, array $groups, array $meta, string $query): array
    {
        $k1 = 1.2;
        $b = 0.75;
        $W = $this->fieldWeights;

        $avg = [
            Indexer::FIELD_TITLE => max(1.0, (float) ($meta['avg_t'] ?? 1.0)),
            Indexer::FIELD_DESC  => max(1.0, (float) ($meta['avg_d'] ?? 1.0)),
            Indexer::FIELD_HEAD  => max(1.0, (float) ($meta['avg_h'] ?? 1.0)),
            Indexer::FIELD_BODY  => max(1.0, (float) ($meta['avg_b'] ?? 1.0)),
        ];

        $docTable = $this->schema->getDocTable();
        $docIds = array_keys($matchedDocs);
        $lens = [];

        foreach (array_chunk($docIds, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->db->prepare(
                "SELECT doc_id, len_t, len_d, len_h, len_b FROM `{$docTable}` WHERE doc_id IN ($ph)"
            );
            $st->execute($chunk);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $lens[(int) $r['doc_id']] = [
                    Indexer::FIELD_TITLE => max(1, (int) $r['len_t']),
                    Indexer::FIELD_DESC  => max(1, (int) $r['len_d']),
                    Indexer::FIELD_HEAD  => max(1, (int) $r['len_h']),
                    Indexer::FIELD_BODY  => max(1, (int) $r['len_b']),
                ];
            }
        }

        // Map term ID to IDF
        $termIdf = [];
        foreach ($groups as $g) {
            foreach ($g['variants'] as $v) {
                $termIdf[$v['id']] = $v['idf'];
            }
        }

        $ranked = [];
        foreach ($docIds as $docId) {
            $L = $lens[$docId] ?? $avg;
            $score = 0.0;
            $matchedFields = [];
            $matchedGroupCount = 0;
            $hasTitleMatch = false;

            foreach ($hits[$docId] as $gi => $byTerm) {
                $bestVariant = 0.0;
                foreach ($byTerm as $tid => $byField) {
                    $wtf = 0.0;
                    foreach ($byField as $f => $tf) {
                        if (!isset($W[$f])) continue;
                        if ((int) $f === Indexer::FIELD_TITLE) {
                            $hasTitleMatch = true;
                        }
                        $norm = 1 - $b + $b * ($L[$f] / $avg[$f]);
                        $wtf += $W[$f] * $tf / $norm;
                        $matchedFields[$f] = true;
                    }
                    if ($wtf > 0) {
                        $s = ($termIdf[$tid] ?? 0.0) * $wtf / ($k1 + $wtf);
                        $bestVariant = max($bestVariant, $s);
                    }
                }
                if ($bestVariant > 0) {
                    $score += $bestVariant;
                    $matchedGroupCount++;
                }
            }

            if ($hasTitleMatch) {
                $score += 8.0; // High bonus for title match
            }

            $ranked[] = [
                'id'     => $docId,
                'score'  => round($score, 3),
                'groups' => $matchedGroupCount,
                'fields' => array_keys($matchedFields),
            ];
        }

        // Sort by number of matched semantic groups first, then BM25F score
        usort($ranked, function ($x, $y) {
            if ($x['groups'] !== $y['groups']) {
                return $y['groups'] <=> $x['groups'];
            }
            return $y['score'] <=> $x['score'];
        });

        return $ranked;
    }

    /**
     * Extract highlight terms from query groups.
     * 
     * @return string[]
     */
    private function extractHighlightTerms(array $groups): array
    {
        $terms = [];
        foreach ($groups as $g) {
            $terms[] = $g['label'];
            foreach ($g['variants'] as $t => $v) {
                if (!empty($v['is_surface'])) {
                    $terms[] = $v['raw'] ?? $t;
                }
            }
        }
        return array_values(array_unique(array_filter($terms)));
    }
}
