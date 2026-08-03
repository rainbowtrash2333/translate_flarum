<?php

declare(strict_types=1);

namespace Twikura\Translate\Event;

use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Settings\SettingsRepositoryInterface;
use Twikura\Translate\LangMapConfig;
use Twikura\Translate\Repository\PostTranslationRepository;

/**
 * Subscribes to Flarum post lifecycle events and enqueues translation jobs.
 *
 * On PostCreated / PostRevised, if auto_translate is enabled and the site
 * default_locale appears in the lang_map, a pending row is inserted into
 * post_translations for the Worker to pick up.
 */
class TranslationEventListeners
{
    private SettingsRepositoryInterface $settings;
    private PostTranslationRepository $repo;

    public function __construct(
        SettingsRepositoryInterface $settings,
        PostTranslationRepository $repo,
    ) {
        $this->settings = $settings;
        $this->repo     = $repo;
    }

    public function onPostPosted(Posted $event): void
    {
        $this->dispatchTranslationJob($event->post);
    }

    public function onPostRevised(Revised $event): void
    {
        $this->dispatchTranslationJob($event->post);
    }

    /**
     * Shared logic: validate pre-conditions and enqueue a pending
     * translation row for the post.
     */
    private function dispatchTranslationJob($post): void
    {
        // 1. Only translate comment posts.
        if (! $post instanceof CommentPost) {
            return;
        }

        // 2. Check auto_translate setting.
        $autoTranslate = (bool) $this->settings->get('twikura-translate.auto_translate', '1');

        if (! $autoTranslate) {
            return;
        }

        // 3. Read site default_locale as the target language.
        $targetLang = trim((string) $this->settings->get('default_locale', ''));

        if ($targetLang === '') {
            return;
        }

        // 3b. Normalize region-code locales (e.g. "zh-Hans" → "zh") so they
        // match the bare-code lang_map keys, and so stored target_lang is
        // consistent with what the frontend sends and the serializer resolves.
        $bareTarget = strtolower(strtok(str_replace('_', '-', $targetLang), '-'));

        if ($bareTarget === '') {
            return;
        }

        // 4. Validate that the target lang exists in lang_map.
        $langMapRaw = (string) $this->settings->get('twikura-translate.lang_map', '');
        $langMap    = LangMapConfig::parse($langMapRaw);

        if (! array_key_exists($bareTarget, $langMap)) {
            // default_locale is not in the configured lang_map — skip.
            return;
        }

        // 5. Enqueue a pending translation row (non-backfill).
        $this->repo->enqueue(
            postId: (int) $post->id,
            targetLang: $bareTarget,
            sourceContent: (string) $post->content,
            isBackfill: false,
        );
    }
}
