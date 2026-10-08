<?php

namespace IndicSearch\Storage;

use PDO;

/**
 * Storage Schema Manager
 * 
 * Manages the inverted index tables in MySQL / MariaDB.
 * Supports customizable table prefixes and automatic schema creation.
 */
class Schema
{
    private PDO $db;
    private string $prefix;

    public function __construct(PDO $db, string $prefix = 'indic_search_')
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getTermTable(): string
    {
        return $this->prefix . 'term';
    }

    public function getPostingTable(): string
    {
        return $this->prefix . 'posting';
    }

    public function getDocTable(): string
    {
        return $this->prefix . 'doc';
    }

    public function getMetaTable(): string
    {
        return $this->prefix . 'meta';
    }

    private static array $tablesChecked = [];

    public function ensureTables(): bool
    {
        $key = $this->prefix;
        if (!empty(self::$tablesChecked[$key])) {
            return true;
        }
        $term = $this->getTermTable();
        $posting = $this->getPostingTable();
        $doc = $this->getDocTable();
        $meta = $this->getMetaTable();

        $queries = [
            "CREATE TABLE IF NOT EXISTS `{$term}` (
                `term_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `term` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `raw` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                `skel` VARCHAR(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
                `df` INT UNSIGNED NOT NULL DEFAULT 0,
                `best_field` TINYINT UNSIGNED NOT NULL DEFAULT 4,
                `is_surface` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`term_id`),
                UNIQUE KEY `uq_term` (`term`),
                KEY `idx_skel` (`skel`),
                KEY `idx_df` (`df`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `{$posting}` (
                `term_id` INT UNSIGNED NOT NULL,
                `doc_id` INT UNSIGNED NOT NULL,
                `field` TINYINT UNSIGNED NOT NULL,
                `tf` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (`term_id`, `doc_id`, `field`),
                KEY `idx_doc` (`doc_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `{$doc}` (
                `doc_id` INT UNSIGNED NOT NULL,
                `len_t` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `len_d` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `len_h` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
                `len_b` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
                `indexed_at` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`doc_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `{$meta}` (
                `mkey` VARCHAR(40) NOT NULL,
                `mval` LONGTEXT NOT NULL,
                PRIMARY KEY (`mkey`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        foreach ($queries as $sql) {
            $this->db->exec($sql);
        }

        self::$tablesChecked[$key] = true;
        return true;
    }

    /**
     * Clear all index data (truncate tables).
     */
    public function clearIndex(): void
    {
        $this->ensureTables();
        $this->db->exec("TRUNCATE TABLE `{$this->getPostingTable()}`");
        $this->db->exec("TRUNCATE TABLE `{$this->getTermTable()}`");
        $this->db->exec("TRUNCATE TABLE `{$this->getDocTable()}`");
        $this->db->exec("TRUNCATE TABLE `{$this->getMetaTable()}`");
    }

    /**
     * Drop all search engine tables.
     */
    public function dropTables(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS `{$this->getPostingTable()}`");
        $this->db->exec("DROP TABLE IF EXISTS `{$this->getTermTable()}`");
        $this->db->exec("DROP TABLE IF EXISTS `{$this->getDocTable()}`");
        $this->db->exec("DROP TABLE IF EXISTS `{$this->getMetaTable()}`");
    }
}
