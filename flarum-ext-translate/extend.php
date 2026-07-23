<?php

use Flarum\Extend;
use Flarum\Post\PostSerializer;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twikura\Translate\Api\Controller\TranslateBackfillController;
use Twikura\Translate\Api\Controller\TranslateBackfillStatusController;
use Twikura\Translate\Api\Controller\TranslateLogController;
use Twikura\Translate\Api\Controller\TranslateRetryController;
use Twikura\Translate\Api\Controller\TranslateTestController;
use Twikura\Translate\Command\TranslateRunCommand;
use Twikura\Translate\Event\TranslationEventListeners;
use Twikura\Translate\Llm\PromptBuilder;
use Twikura\Translate\Repository\PostTranslationRepository;

return [
    // ------------------------------------------------------------------
    // Frontends
    // ------------------------------------------------------------------
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    // ------------------------------------------------------------------
    // Console command (long-running worker)
    // ------------------------------------------------------------------
    (new Extend\Console())
        ->command(TranslateRunCommand::class),

    // ------------------------------------------------------------------
    // API routes
    // ------------------------------------------------------------------
    (new Extend\Routes('api'))
        ->post('/translate/retry', 'twikura-translate.retry', TranslateRetryController::class)
        ->post('/translate/test', 'twikura-translate.test', TranslateTestController::class)
        ->post('/translate/backfill', 'twikura-translate.backfill', TranslateBackfillController::class)
        ->get('/translate-logs', 'twikura-translate.logs', TranslateLogController::class)
        ->get('/translate/backfill/status', 'twikura-translate.backfill-status', TranslateBackfillStatusController::class),

    // ------------------------------------------------------------------
    // Event listeners — auto-enqueue translation on post create / revise
    // ------------------------------------------------------------------
    (new Extend\Event())
        ->listen(
            \Flarum\Post\Event\Created::class,
            [TranslationEventListeners::class, 'onPostCreated'],
        )
        ->listen(
            \Flarum\Post\Event\Revised::class,
            [TranslationEventListeners::class, 'onPostRevised'],
        ),

    // ------------------------------------------------------------------
    // PostSerializer — inject translation fields into every post response
    // ------------------------------------------------------------------
    (new Extend\ApiSerializer(PostSerializer::class))
        ->attributes(function (PostSerializer $serializer, $post, array $attributes): array {
            /** @var SettingsRepositoryInterface $settings */
            $settings = resolve(SettingsRepositoryInterface::class);

            /** @var PostTranslationRepository $repo */
            $repo = resolve(PostTranslationRepository::class);

            /** @var ServerRequestInterface|null $request */
            $request = resolve(ServerRequestInterface::class);

            // Determine the target language for this request:
            //   1. Query param ?lang= (explicit front-end override)
            //   2. Fall back to site default_locale
            $targetLang = null;

            if ($request !== null) {
                $params      = $request->getQueryParams();
                $targetLang  = isset($params['lang']) ? trim((string) $params['lang']) : null;
            }

            if ($targetLang === null || $targetLang === '') {
                $targetLang = trim((string) $settings->get('default_locale', ''));
            }

            // No target language available — attach null fields.
            if ($targetLang === '' || $targetLang === null) {
                $attributes['translation_status']      = null;
                $attributes['translated_content']      = null;
                $attributes['translation_error']       = null;
                $attributes['translation_target_lang'] = null;

                return $attributes;
            }

            $row = $repo->findForPost((int) $post->id, $targetLang);

            if ($row === null) {
                $attributes['translation_status']      = null;
                $attributes['translated_content']      = null;
                $attributes['translation_error']       = null;
                $attributes['translation_target_lang'] = null;
            } else {
                $attributes['translation_status']      = $row['status'];
                $attributes['translated_content']      = $row['translated_content'] ?? null;
                $attributes['translation_error']       = $row['error'] ?? null;
                $attributes['translation_target_lang'] = $row['target_lang'];
            }

            return $attributes;
        }),

    // ------------------------------------------------------------------
    // Settings — defaults + serialized forum payload
    // ------------------------------------------------------------------
    (new Extend\Settings())
        ->default('twikura-translate.auto_translate', '1')
        ->default('twikura-translate.llm_base_url', 'http://opencode-go:3000')
        ->default('twikura-translate.llm_api_key', '')
        ->default('twikura-translate.llm_model', 'deepseek-v4-flash')
        ->default('twikura-translate.llm_timeout', '120')
        ->default('twikura-translate.llm_max_retries', '1')
        ->default('twikura-translate.lang_map', <<<'LANGMAP'
zh:Simplified Chinese
ja:Japanese
en:English
ko:Korean
fr:French
de:German
es:Spanish
ru:Russian
pt:Portuguese
th:Thai
vi:Vietnamese
ar:Arabic
LANGMAP)
        ->default('twikura-translate.system_prompt', PromptBuilder::DEFAULT_SYSTEM_PROMPT)
        ->default('twikura-translate.allow_guests', '1')
        ->serializeToForum(
            'twikuraTranslateAutoEnabled',
            'twikura-translate.auto_translate',
            'boolval',
            true,
        )
        ->serializeToForum(
            'twikuraTranslateAllowGuests',
            'twikura-translate.allow_guests',
            'boolval',
            true,
        ),

    // ------------------------------------------------------------------
    // Locales
    // ------------------------------------------------------------------
    new Extend\Locales(__DIR__ . '/locale'),
];
