<?php

namespace Twikura\Translate\Repository;

use Illuminate\Database\ConnectionInterface;

class PostTranslationRepository
{
    private ConnectionInterface $db;
    private string $prefix;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
        $this->prefix = $db->getTablePrefix();
    }

    /**
     * Insert or wake a pending translation row.
     *
     * INSERT … ON DUPLICATE KEY UPDATE: sets status back to 'pending',
     * updates source_content (stale-detection snapshot), clears error.
     * is_backfill is NOT overwritten on duplicate — bulk-fill rows stay marked.
     */
    public function enqueue(int $postId, string $targetLang, string $sourceContent, bool $isBackfill = false): void
    {
        $this->db->statement(
            "INSERT INTO {$this->prefix}post_translations (post_id, target_lang, source_content, status, is_backfill, created_at, updated_at)
             VALUES (?, ?, ?, 'pending', ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 status = 'pending',
                 source_content = VALUES(source_content),
                 error = NULL,
                 translated_content = NULL,
                 translated_content_html = NULL",
            [$postId, $targetLang, $sourceContent, (int) $isBackfill]
        );
    }

    /**
     * Find the translation row for a specific post + language.
     * Used by PostSerializer to inject translation fields.
     */
    public function findForPost(int $postId, string $targetLang): ?array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM {$this->prefix}post_translations
             WHERE post_id = ? AND target_lang = ?",
            [$postId, $targetLang]
        );

        return $row !== null ? (array) $row : null;
    }

    /**
     * Find the translation row for a post, tolerating locale-code shape
     * mismatches between the request locale and the stored target_lang.
     *
     * Lookup order:
     *   1. Exact match (post_id + target_lang)
     *   2. Region-code requested → bare code (e.g. "zh-Hans" → "zh")
     *   3. Bare code requested → exact, else any stored region variant
     *      (e.g. "zh" → "zh-Hans", for rows written by older builds)
     */
    public function findForPostFlexible(int $postId, string $targetLang): ?array
    {
        $row = $this->findForPost($postId, $targetLang);

        if ($row !== null) {
            return $row;
        }

        if (str_contains($targetLang, '-') || str_contains($targetLang, '_')) {
            $bare = strtolower(strtok(str_replace('_', '-', $targetLang), '-'));

            if ($bare === '') {
                return null;
            }

            return $this->findForPost($postId, $bare);
        }

        // Bare code requested — accept any stored region variant.
        $found = $this->db->selectOne(
            "SELECT * FROM {$this->prefix}post_translations"
            . " WHERE post_id = ? AND (target_lang = ? OR target_lang LIKE ?)"
            . " ORDER BY (target_lang = ?) DESC, created_at DESC"
            . " LIMIT 1",
            [$postId, $targetLang, $targetLang . '-%', $targetLang]
        );

        return $found !== null ? (array) $found : null;
    }

    /**
     * Count translation rows by status for a given target language.
     *
     * Returns: ['pending' => N, 'running' => N, 'done' => N, 'error' => N]
     */
    public function getStatusCounts(string $targetLang): array
    {
        $rows = $this->db->select(
            "SELECT status, COUNT(*) AS cnt
             FROM {$this->prefix}post_translations
             WHERE target_lang = ?
             GROUP BY status",
            [$targetLang]
        );

        $counts = [
            'pending' => 0,
            'running' => 0,
            'done'    => 0,
            'error'   => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->cnt;
        }

        return $counts;
    }

    /**
     * Scan for comment posts that are missing or stale for a given target language.
     *
     * Returns up to $limit post IDs that need (re-)translation.
     * A post is stale when post_translations.source_content != posts.content.
     * Only comment posts are included — system post types are skipped.
     */
    public function findStalePostIds(string $targetLang, int $limit = 5000): array
    {
        $rows = $this->db->select(
            "SELECT p.id
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}post_translations t
                 ON t.post_id = p.id AND t.target_lang = ?
             WHERE p.type = 'comment'
                 AND (t.post_id IS NULL OR t.source_content != p.content OR t.status = 'error')
             ORDER BY p.id ASC
             LIMIT ?",
            [$targetLang, $limit]
        );

        return array_map(function ($row) {
            return (int) $row->id;
        }, $rows);
    }
}