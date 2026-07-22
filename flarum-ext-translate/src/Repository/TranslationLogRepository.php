<?php

namespace Twikura\Translate\Repository;

use Illuminate\Database\ConnectionInterface;

class TranslationLogRepository
{
    private ConnectionInterface $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Insert a single translation log entry.
     *
     * $data keys:
     *   post_id, target_lang, prompt_full, response_final,
     *   source_content, translated_content, tokens_in, tokens_out,
     *   latency_ms, status, error
     */
    public function log(array $data): int
    {
        $insertId = $this->db->table('translation_logs')->insertGetId([
            'post_id'            => $data['post_id'],
            'target_lang'        => $data['target_lang'],
            'prompt_full'        => $data['prompt_full'],
            'response_final'     => $data['response_final'],
            'source_content'     => $data['source_content'],
            'translated_content' => $data['translated_content'],
            'tokens_in'          => $data['tokens_in'] ?? null,
            'tokens_out'         => $data['tokens_out'] ?? null,
            'latency_ms'         => $data['latency_ms'] ?? null,
            'status'             => $data['status'],
            'error'              => $data['error'] ?? null,
            'created_at'         => $this->db->raw('NOW()'),
        ]);

        return (int) $insertId;
    }

    /**
     * Paginated query for admin log view.
     *
     * Returns: ['data' => [...], 'total' => N, 'page' => N, 'perPage' => N]
     */
    public function paginated(int $page = 1, int $perPage = 50, ?string $lang = null, ?string $status = null): array
    {
        $query = $this->db->table('translation_logs');

        if ($lang !== null && $lang !== '') {
            $query->where('target_lang', $lang);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $total = $query->count();
        $rows  = $query
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->toArray();

        return [
            'data'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Count total translation log entries (optionally filtered).
     */
    public function count(?string $lang = null, ?string $status = null): int
    {
        $query = $this->db->table('translation_logs');

        if ($lang !== null && $lang !== '') {
            $query->where('target_lang', $lang);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->count();
    }
}
