<?php

namespace IndicSearch;

use PDO;
use IndicSearch\Storage\Schema;
use IndicSearch\NLP\Devanagari;
use IndicSearch\NLP\Skeleton;

/**
 * Document Indexer
 * 
 * Builds and updates the inverted index (search_term, search_posting, search_doc, search_meta)
 * for fast BM25F ranking and O(1) skeleton typo matching.
 */
class Indexer
{
    private PDO $db;
    private Schema $schema;

    public const FIELD_TITLE = 1;
    public const FIELD_DESC  = 2;
    public const FIELD_HEAD  = 3;
    public const FIELD_BODY  = 4;

    public function __construct(PDO $db, Schema $schema)
    {
        $this->db = $db;
        $this->schema = $schema;
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
     *     content?: string, // Alias for body
     * } $fields Document text fields
     */
    public function index(int $docId, array $fields): bool
    {
        $this->schema->ensureTables();

        $title = (string) ($fields['title'] ?? '');
        $desc  = (string) ($fields['description'] ?? '');
        $head  = (string) ($fields['headings'] ?? '');
        $body  = (string) ($fields['body'] ?? $fields['content'] ?? '');

        // If body has HTML headings and headings field is empty, extract them automatically
        if ($head === '' && $body !== '') {
            $head = Tokenizer::extractHeadings($body);
        }

        // Clean HTML from body
        $cleanBody = Tokenizer::cleanHtml($body);

        $fieldContents = [
            self::FIELD_TITLE => $title,
            self::FIELD_DESC  => $desc,
            self::FIELD_HEAD  => $head,
            self::FIELD_BODY  => $cleanBody,
        ];

        // 1. Remove previous postings and doc info for this docId
        $this->delete($docId, false);

        // 2. Tokenize each field
        $fieldTokens = [];
        $fieldLens = [];
        $allUniqueTerms = [];

        foreach ($fieldContents as $fieldCode => $content) {
            $tokensData = Tokenizer::tokenize($content);
            $fieldTokens[$fieldCode] = $tokensData['tf'];
            $fieldLens[$fieldCode] = array_sum($tokensData['tf']);

            foreach ($tokensData['tf'] as $term => $tf) {
                if (!isset($allUniqueTerms[$term])) {
                    $allUniqueTerms[$term] = [
                        'raw' => $term,
                        'skel' => Skeleton::generate($term),
                        'is_surface' => !empty($tokensData['surface'][$term]) ? 1 : 0,
                        'best_field' => $fieldCode,
                    ];
                } else {
                    if (!empty($tokensData['surface'][$term])) {
                        $allUniqueTerms[$term]['is_surface'] = 1;
                    }
                    if ($fieldCode < $allUniqueTerms[$term]['best_field']) {
                        $allUniqueTerms[$term]['best_field'] = $fieldCode;
                    }
                }
            }
        }

        // 3. Upsert terms into search_term table
        $termTable = $this->schema->getTermTable();
        $postingTable = $this->schema->getPostingTable();
        $docTable = $this->schema->getDocTable();

        $termIds = [];
        if (!empty($allUniqueTerms)) {
            // Find existing terms
            $chunks = array_chunk(array_keys($allUniqueTerms), 200);
            foreach ($chunks as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $this->db->prepare("SELECT term_id, term FROM `{$termTable}` WHERE term IN ($ph)");
                $st->execute($chunk);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $termIds[$r['term']] = (int) $r['term_id'];
                }
            }

            // Insert new terms
            $insertSt = $this->db->prepare(
                "INSERT INTO `{$termTable}` (term, raw, skel, best_field, is_surface)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    best_field = LEAST(best_field, VALUES(best_field)),
                    is_surface = GREATEST(is_surface, VALUES(is_surface))"
            );

            foreach ($allUniqueTerms as $term => $meta) {
                if (!isset($termIds[$term])) {
                    $insertSt->execute([
                        $term,
                        mb_substr($meta['raw'], 0, 64, 'UTF-8'),
                        $meta['skel'],
                        $meta['best_field'],
                        $meta['is_surface'],
                    ]);
                    $termIds[$term] = (int) $this->db->lastInsertId();
                }
            }
        }

        // 4. Insert postings
        if (!empty($termIds)) {
            $postSt = $this->db->prepare(
                "INSERT INTO `{$postingTable}` (term_id, doc_id, field, tf)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE tf = VALUES(tf)"
            );

            foreach ($fieldTokens as $fieldCode => $tfs) {
                foreach ($tfs as $term => $tf) {
                    if (isset($termIds[$term])) {
                        $postSt->execute([
                            $termIds[$term],
                            $docId,
                            $fieldCode,
                            $tf
                        ]);
                    }
                }
            }
        }

        // 5. Insert document lengths
        $docSt = $this->db->prepare(
            "REPLACE INTO `{$docTable}` (doc_id, len_t, len_d, len_h, len_b, indexed_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $docSt->execute([
            $docId,
            $fieldLens[self::FIELD_TITLE] ?? 0,
            $fieldLens[self::FIELD_DESC] ?? 0,
            $fieldLens[self::FIELD_HEAD] ?? 0,
            $fieldLens[self::FIELD_BODY] ?? 0,
            time()
        ]);

        return true;
    }

    /**
     * Index a batch of documents.
     * 
     * @param array<int, array{id: int, title?: string, description?: string, headings?: string, body?: string}> $documents
     * @return int Number of documents indexed
     */
    public function indexBatch(array $documents): int
    {
        $this->schema->ensureTables();
        $this->db->beginTransaction();
        $count = 0;

        try {
            foreach ($documents as $doc) {
                $docId = (int) ($doc['id'] ?? 0);
                if ($docId <= 0) continue;
                $this->index($docId, $doc);
                $count++;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->recomputeStats();
        return $count;
    }

    /**
     * Delete a document from the index.
     */
    public function delete(int $docId, bool $recompute = true): bool
    {
        $postingTable = $this->schema->getPostingTable();
        $docTable = $this->schema->getDocTable();

        $st1 = $this->db->prepare("DELETE FROM `{$postingTable}` WHERE doc_id = ?");
        $st1->execute([$docId]);

        $st2 = $this->db->prepare("DELETE FROM `{$docTable}` WHERE doc_id = ?");
        $st2->execute([$docId]);

        if ($recompute) {
            $this->recomputeStats();
        }

        return true;
    }

    /**
     * Recompute corpus-wide statistics:
     * - Document frequency (df) for all terms
     * - Total document count
     * - Average lengths for BM25F normalization
     */
    public function recomputeStats(): void
    {
        $termTable = $this->schema->getTermTable();
        $postingTable = $this->schema->getPostingTable();
        $docTable = $this->schema->getDocTable();
        $metaTable = $this->schema->getMetaTable();

        // 1. Recompute df
        $this->db->exec(
            "UPDATE `{$termTable}` t
             SET df = (
                 SELECT COUNT(DISTINCT doc_id)
                 FROM `{$postingTable}` p
                 WHERE p.term_id = t.term_id
             )"
        );

        // Remove orphan terms (df = 0)
        $this->db->exec("DELETE FROM `{$termTable}` WHERE df = 0");

        // 2. Compute doc counts and average lengths
        $docStats = $this->db->query(
            "SELECT 
                COUNT(*) as doc_count,
                AVG(len_t) as avg_t,
                AVG(len_d) as avg_d,
                AVG(len_h) as avg_h,
                AVG(len_b) as avg_b
             FROM `{$docTable}`"
        )->fetch(PDO::FETCH_ASSOC);

        $docCount = (int) ($docStats['doc_count'] ?? 0);
        $avgT = (float) ($docStats['avg_t'] ?? 1.0);
        $avgD = (float) ($docStats['avg_d'] ?? 1.0);
        $avgH = (float) ($docStats['avg_h'] ?? 1.0);
        $avgB = (float) ($docStats['avg_b'] ?? 1.0);

        $setMeta = $this->db->prepare("REPLACE INTO `{$metaTable}` (mkey, mval) VALUES (?, ?)");
        $setMeta->execute(['doc_count', (string) $docCount]);
        $setMeta->execute(['avg_t', (string) max(1.0, $avgT)]);
        $setMeta->execute(['avg_d', (string) max(1.0, $avgD)]);
        $setMeta->execute(['avg_h', (string) max(1.0, $avgH)]);
        $setMeta->execute(['avg_b', (string) max(1.0, $avgB)]);
        $setMeta->execute(['updated_at', (string) time()]);
    }
}
