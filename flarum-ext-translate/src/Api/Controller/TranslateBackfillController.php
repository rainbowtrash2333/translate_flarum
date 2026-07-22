<?php

declare(strict_types=1);

namespace Twikura\Translate\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/translate/backfill    (admin only)
 *
 * Scans all comment posts for missing or stale translations for a given
 * target language and bulk-inserts pending rows via a single INSERT … SELECT
 * statement (with ON DUPLICATE KEY UPDATE for stale rows).
 *
 * Returns the actual number of stale posts discovered and the number of rows
 * actually inserted/updated (capped by BACKFILL_LIMIT).
 */
class TranslateBackfillController implements RequestHandlerInterface
{
    private const BACKFILL_LIMIT = 5000;

    private ConnectionInterface $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // --- Admin-only guard --------------------------------------------------
        $actor = RequestUtil::getActor($request);

        if (! $actor->isAdmin()) {
            return new JsonResponse([
                'errors' => [[
                    'status' => '403',
                    'detail' => 'This endpoint is restricted to administrators.',
                ]],
            ], 403);
        }

        // --- Parse body --------------------------------------------------------
        $body = $this->parseBody($request);
        $lang = isset($body['lang']) ? trim((string) $body['lang']) : '';

        if ($lang === '') {
            return new JsonResponse([
                'errors' => [[
                    'status' => '400',
                    'detail' => 'lang is required.',
                ]],
            ], 400);
        }

        $prefix = $this->db->getTablePrefix();

        // --- Count ALL stale comment posts (not capped) -----------------------
        $countRow = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}post_translations t
                 ON t.post_id = p.id AND t.target_lang = ?
             WHERE p.type = 'comment'
                 AND (t.post_id IS NULL OR t.source_content != p.content)",
            [$lang],
        );

        $scanned = $countRow !== null ? (int) $countRow->cnt : 0;

        // --- Bulk insert / update stale rows (capped) --------------------------
        // New posts get a fresh pending row (is_backfill=1).
        // Existing stale rows are reset to pending (status, source_content, error cleared).
        // is_backfill is NOT overwritten on duplicate — preserves event-triggered priority.
        $inserted = $this->db->affectingStatement(
            "INSERT INTO {$prefix}post_translations
                 (post_id, target_lang, source_content, status, is_backfill, created_at, updated_at)
             SELECT p.id, ?, p.content, 'pending', 1, NOW(), NOW()
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}post_translations t
                 ON t.post_id = p.id AND t.target_lang = ?
             WHERE p.type = 'comment'
                 AND (t.post_id IS NULL OR t.source_content != p.content)
             ORDER BY p.id ASC
             LIMIT ?
             ON DUPLICATE KEY UPDATE
                 status = 'pending',
                 source_content = VALUES(source_content),
                 error = NULL",
            [$lang, $lang, self::BACKFILL_LIMIT],
        );

        return new JsonResponse([
            'scanned'  => $scanned,
            'inserted' => $inserted,
            'capped'   => $scanned > self::BACKFILL_LIMIT,
        ]);
    }

    /**
     * Safely extract the parsed body from the request.
     *
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        if (is_array($body)) {
            return $body;
        }

        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}