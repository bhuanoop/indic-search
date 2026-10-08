<?php

namespace IndicSearch;

use PDO;
use IndicSearch\Storage\Schema;
use IndicSearch\Dictionary\Stopwords;
use IndicSearch\Dictionary\Synonyms;
use IndicSearch\NLP\Transliteration;
use IndicSearch\Highlighting\Highlighter;

/**
 * IndicSearch — Unified Multilingual Indic Search Engine
 * 
 * Originally engineered for and battle-tested by Think Homeo (https://thinkhomeo.in).
 * 
 * @author Think Homeo <https://thinkhomeo.in>
 * @copyright 2026 Think Homeo
 * @license MIT
 * @link https://thinkhomeo.in
 * 
 * A high-performance, lightweight search engine for PHP & MySQL/MariaDB
 * supporting Hindi (Devanagari), Hinglish, and English with:
 * - O(1) Phonetic Skeleton Typo-Tolerance
 * - Devanagari Akshara Weighted Cluster Edit Distance
 * - Hinglish Phonetic Transliteration
 * - BM25F Multi-field Ranking
 * - Dynamic Query Relaxation (zero empty-result fallback)
 * - Inverted Index with Length Normalization
 */
class IndicSearch
{
    private PDO $db;
    private Schema $schema;
    private Indexer $indexer;
    private Searcher $searcher;
    private Stopwords $stopwords;
    private Synonyms $synonyms;
    private Transliteration $transliteration;

    /**
     * @param PDO $db Active PDO connection to MySQL / MariaDB
     * @param array{
     *     table_prefix?: string,
     *     enable_google_transliteration?: bool,
     *     api_timeout?: int,
     *     max_api_calls?: int,
     *     custom_synonyms_path?: string,
     *     custom_soft_words?: string[],
     *     field_weights?: array<int, float>
     * } $options Configuration options
     */
    public function __construct(PDO $db, array $options = [])
    {
        $this->db = $db;
        $prefix = (string) ($options['table_prefix'] ?? 'indic_search_');

        $this->schema = new Schema($this->db, $prefix);
        $this->stopwords = new Stopwords((array) ($options['custom_soft_words'] ?? []));
        $this->synonyms = new Synonyms($options['custom_synonyms_path'] ?? null);

        $enableTranslit = (bool) ($options['enable_google_transliteration'] ?? true);
        $apiTimeout = (int) ($options['api_timeout'] ?? 2);
        $maxApiCalls = (int) ($options['max_api_calls'] ?? 4);
        $this->transliteration = new Transliteration($enableTranslit, $apiTimeout, $maxApiCalls);

        $this->indexer = new Indexer($this->db, $this->schema);
        $this->searcher = new Searcher(
            $this->db,
            $this->schema,
            $this->stopwords,
            $this->synonyms,
            $this->transliteration
        );

        if (!empty($options['field_weights'])) {
            $this->searcher->setFieldWeights($options['field_weights']);
        }
    }

    /**
     * Ensure database tables exist.
     */
    public function install(): bool
    {
        return $this->schema->ensureTables();
    }

    /**
     * Index a single document.
     * 
     * @param int $docId Unique document ID
     * @param array{
     *     title?: string,
     *     description?: string,
     *     headings?: string,
     *     body?: string,
     *     content?: string
     * } $fields Document fields
     */
    public function index(int $docId, array $fields): bool
    {
        return $this->indexer->index($docId, $fields);
    }

    /**
     * Index a batch of documents.
     * 
     * @param array<int, array{id: int, title?: string, description?: string, body?: string}> $documents
     * @return int Number of indexed documents
     */
    public function indexBatch(array $documents): int
    {
        return $this->indexer->indexBatch($documents);
    }

    /**
     * Delete a document from index.
     */
    public function delete(int $docId): bool
    {
        return $this->indexer->delete($docId);
    }

    /**
     * Clear the search index.
     */
    public function clearIndex(): void
    {
        $this->schema->clearIndex();
    }

    /**
     * Execute full-text search.
     * 
     * @param string $query User query (Hindi, Hinglish, or English)
     * @param int $limit Max results (default 10)
     * @param array $options Search options
     * @return array
     */
    public function search(string $query, int $limit = 10, array $options = []): array
    {
        return $this->searcher->search($query, $limit, $options);
    }

    /**
     * Instant autocomplete suggestions for as-you-type input.
     * 
     * @param string $query
     * @param int $limit
     * @return string[]
     */
    public function suggest(string $query, int $limit = 6): array
    {
        return $this->searcher->suggest($query, $limit);
    }

    /**
     * Highlight search terms in a text string.
     */
    public function highlight(string $text, array $terms, string $tag = 'mark', string $cssClass = 'indic-highlight'): string
    {
        return Highlighter::highlight($text, $terms, $tag, $cssClass);
    }

    /**
     * Create an intelligent snippet around matched terms.
     */
    public function snippet(string $htmlContent, array $terms, int $length = 160): string
    {
        return Highlighter::createSnippet($htmlContent, $terms, $length);
    }

    /**
     * Get the Indexer instance.
     */
    public function getIndexer(): Indexer
    {
        return $this->indexer;
    }

    /**
     * Get the Searcher instance.
     */
    public function getSearcher(): Searcher
    {
        return $this->searcher;
    }

    /**
     * Get the Stopwords manager.
     */
    public function getStopwords(): Stopwords
    {
        return $this->stopwords;
    }

    /**
     * Get the Synonyms dictionary.
     */
    public function getSynonyms(): Synonyms
    {
        return $this->synonyms;
    }
}
