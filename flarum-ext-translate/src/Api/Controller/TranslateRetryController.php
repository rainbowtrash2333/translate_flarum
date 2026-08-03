<?php

declare(strict_types=1);

namespace Twikura\Translate\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twikura\Translate\Repository\PostTranslationRepository;

/**
 * POST /api/translate/retry
 *
 * Resets a failed (status=error) translation row back to pending, or
 * creates a fresh pending row for a post that has never been translated
 * (manual translate). Rows that are already pending/running/done are
 * returned as-is.
 *
 * Permission: authenticated users, or guests when allow_guests is enabled.
 */
class TranslateRetryController implements RequestHandlerInterface
{
    private SettingsRepositoryInterface $settings;
    private PostTranslationRepository $repo;
    private ConnectionInterface $db;

    public function __construct(
        SettingsRepositoryInterface $settings,
        PostTranslationRepository $repo,
        ConnectionInterface $db,
    ) {
        $this->settings = $settings;
        $this->repo     = $repo;
        $this->db       = $db;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // --- Guest check -------------------------------------------------------
        $actor       = RequestUtil::getActor($request);
        $allowGuests = (bool) $this->settings->get('twikura-translate.allow_guests', '1');

        if ($actor->isGuest() && ! $allowGuests) {
            return new JsonResponse([
                'errors' => [[
                    'status' => '403',
                    'detail' => 'Guest translation is disabled.',
                ]],
            ], 403);
        }

        // --- Parse body --------------------------------------------------------
        $body       = $this->parseBody($request);
        $postId     = isset($body['post_id']) ? (int) $body['post_id'] : 0;
        $targetLang = isset($body['target_lang']) ? trim((string) $body['target_lang']) : '';

        if ($postId <= 0 || $targetLang === '') {
            return new JsonResponse([
                'errors' => [[
                    'status' => '400',
                    'detail' => 'post_id and target_lang are required.',
                ]],
            ], 400);
        }

        // --- Find the existing row ------------------------------------------------
        $row = $this->repo->findForPost($postId, $targetLang);

        // Already pending / running / done — nothing to do.
        if ($row !== null && in_array($row['status'], ['pending', 'running', 'done'], true)) {
            return new JsonResponse(['status' => $row['status']]);
        }

        // --- Fetch current post content (not the stale cached snapshot) --------
        // If the post was edited after the failed translation, the cached
        // source_content would be stale.  We read the live posts.content.
        $post = $this->db->table('posts')->where('id', $postId)->first(['content']);

        if ($post === null && $row === null) {
            return new JsonResponse([
                'errors' => [[
                    'status' => '404',
                    'detail' => 'Post not found.',
                ]],
            ], 404);
        }

        $currentContent = $post !== null ? (string) $post->content : (string) $row['source_content'];

        // --- Reset to pending via enqueue (ON DUPLICATE KEY UPDATE) ------------
        // enqueue sets status='pending', error=NULL, updates source_content
        // to the fresh snapshot.  is_backfill is preserved per-spec.
        $this->repo->enqueue(
            postId: $postId,
            targetLang: $targetLang,
            sourceContent: $currentContent,
            isBackfill: (bool) ($row['is_backfill'] ?? false),
        );

        return new JsonResponse(['status' => 'pending']);
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
