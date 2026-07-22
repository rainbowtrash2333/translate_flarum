<?php

declare(strict_types=1);

namespace Twikura\Translate\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twikura\Translate\Llm\OpenAiSseClient;
use Twikura\Translate\Llm\PromptBuilder;

/**
 * POST /api/translate/test    (admin only)
 *
 * Synchronously translates a short text snippet via the configured LLM
 * gateway (opencode-go SSE).  Useful for testing prompt quality and
 * connectivity from the admin panel.
 */
class TranslateTestController implements RequestHandlerInterface
{
    private SettingsRepositoryInterface $settings;
    private PromptBuilder $promptBuilder;

    public function __construct(
        SettingsRepositoryInterface $settings,
        PromptBuilder $promptBuilder,
    ) {
        $this->settings      = $settings;
        $this->promptBuilder = $promptBuilder;
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
        $text = isset($body['text']) ? trim((string) $body['text']) : '';
        $lang = isset($body['lang']) ? trim((string) $body['lang']) : '';

        if ($text === '' || $lang === '') {
            return new JsonResponse([
                'errors' => [[
                    'status' => '400',
                    'detail' => 'text and lang are required.',
                ]],
            ], 400);
        }

        // --- Read LLM configuration from settings ------------------------------
        $baseUrl    = (string) $this->settings->get('twikura-translate.llm_base_url', 'http://opencode-go:3000');
        $apiKey     = (string) $this->settings->get('twikura-translate.llm_api_key', '');
        $model      = (string) $this->settings->get('twikura-translate.llm_model', 'deepseek-v4-flash');
        $timeout    = max(10, (int) $this->settings->get('twikura-translate.llm_timeout', '120'));
        $maxRetries = max(0, (int) $this->settings->get('twikura-translate.llm_max_retries', '1'));

        // --- Build messages ----------------------------------------------------
        $messages = $this->promptBuilder->buildMessages($text, $lang);

        // --- Synchronous SSE translation ---------------------------------------
        // No onToken callback here: echoing inside an HTTP handler corrupts
        // the JSON response (output buffer contamination).  The worker (CLI)
        // uses the callback for docker logs; the admin test endpoint must
        // return clean JSON only.
        $sseClient = new OpenAiSseClient();

        try {
            $result = $sseClient->translate(
                messages: $messages,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                model: $model,
                timeout: $timeout,
                maxRetries: $maxRetries,
            );
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'errors' => [[
                    'status' => '502',
                    'detail' => 'Translation failed: ' . $e->getMessage(),
                ]],
            ], 502);
        }

        return new JsonResponse([
            'translated' => $result['content'],
            'tokens_in'  => $result['tokens_in'],
            'tokens_out' => $result['tokens_out'],
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
