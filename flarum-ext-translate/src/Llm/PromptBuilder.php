<?php

namespace Twikura\Translate\Llm;

use Flarum\Settings\SettingsRepositoryInterface;
use Twikura\Translate\LangMapConfig;

class PromptBuilder
{
    private SettingsRepositoryInterface $settings;

    /**
     * Default system prompt (from fastapi-translator/app/providers.py::_build_prompt,
     * adapted for single-post-no-JSON-format).
     */
    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
You are a professional translator expert in the ACGN (Anime, Comic, Games, Novel) field, gaming culture, and internet slang.
Please translate the provided forum post content into the specified target language according to the rules below.

Rules:
1. Target Language Detection (Crucial): If the source text is already in the target language, or if its core valid content is already in the target language, return the original text EXACTLY as it is. Do not force a secondary translation or localization.
2. Output Format: Return ONLY the translated text. Do not output any extra text, explanations, or Markdown code block wrappers.
3. Tone & Style: All translated content must align with the communication habits of online forums and gaming communities (natural, colloquial, and engaging). Accurately convey ACGN terminology, gaming jargon, and memes if present.
4. BBCode & Formatting Preservation: Strictly preserve all forum rich text formatting within the post. This includes BBCode tags (e.g., [b], [i], [url], [img], [code]), Markdown syntax (**, #), Emojis, and HTML tags. Do not alter, omit, or translate the tags themselves; only translate the text inside or around them, adapting their positions to fit the natural word order.
5. Forum Elements & Quotes: If the original post contains forum-specific elements like @usernames, #hashtags, or forum quote blocks ([quote]...[/quote]), keep them exactly as they are. Never translate user names or configuration attributes within tags.
6. Code Block Protection: If the post contains programming code blocks (e.g., ```javascript ... ``` or [code]...[/code]), DO NOT translate the content inside them. Only translate the discussion text before or after the code blocks.
7. Completeness: Strictly maintain the full content. Do not merge, truncate, summarize, or omit any text content.
8. Plain Output: The output must be plain text. Do not wrap it in Markdown code blocks (like ```json ... ```), and do not include any introductory or concluding pleasantries.

TARGET_LANGUAGE: {lang_name}
PROMPT;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Build the system + user messages for a single-post translation request.
     *
     * @param string $sourceContent Raw post HTML/BBCode content (user message).
     * @param string $targetLang    Target language code (e.g. "zh", "ja").
     *
     * @return array{system: string, user: string}
     */
    public function buildPrompt(string $sourceContent, string $targetLang): array
    {
        $langName = LangMapConfig::getName(
            (string) $this->settings->get('twikura-translate.lang_map', ''),
            $targetLang
        );

        $systemTemplate = (string) $this->settings->get(
            'twikura-translate.system_prompt',
            self::DEFAULT_SYSTEM_PROMPT
        );

        // If admin cleared or the setting yields an empty string, fall back to default.
        if (trim($systemTemplate) === '') {
            $systemTemplate = self::DEFAULT_SYSTEM_PROMPT;
        }

        $system = str_replace('{lang_name}', $langName, $systemTemplate);

        return [
            'system' => $system,
            'user'   => $sourceContent,
        ];
    }

    /**
     * Build messages in the OpenAI-compatible array format.
     *
     * @return array{role: string, content: string}[]
     */
    public function buildMessages(string $sourceContent, string $targetLang): array
    {
        $prompt = $this->buildPrompt($sourceContent, $targetLang);

        return [
            ['role' => 'system', 'content' => $prompt['system']],
            ['role' => 'user',   'content' => $prompt['user']],
        ];
    }
}
