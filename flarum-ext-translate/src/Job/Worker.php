<?php

declare(strict_types=1);

namespace Twikura\Translate\Job;

use Illuminate\Database\ConnectionInterface;
use Twikura\Translate\Llm\OpenAiSseClient;
use Twikura\Translate\Llm\PromptBuilder;

/**
 * Process a single post translation.
 *
 * Called by TranslateRunCommand.  Handles the full lifecycle for one row:
 * build messages, stream-translate via SSE, write results back to
 * post_translations and translation_logs inside a DB transaction.
 */
final class Worker
{
    /**
     * @param PromptBuilder       $promptBuilder  Builds system + user messages.
     * @param OpenAiSseClient     $sseClient      SSE streaming curl client.
     * @param ConnectionInterface $db             Flarum database connection.
     * @param string              $llmBaseUrl     opencode-go base URL (from settings).
     * @param string              $llmApiKey      API key (from settings).
     * @param string              $llmModel       Model ID (from settings).
     * @param int                 $llmTimeout     Overall request timeout in seconds.
     * @param int                 $llmMaxRetries  Max retries on transient errors.
     */
    public function __construct(
        private PromptBuilder $promptBuilder,
        private OpenAiSseClient $sseClient,
        private ConnectionInterface $db,
        private string $llmBaseUrl,
        private string $llmApiKey,
        private string $llmModel,
        private int $llmTimeout = 120,
        private int $llmMaxRetries = 1,
    ) {
    }

    /**
     * Translate one post and write the result.
     *
     * @param array{post_id: int, target_lang: string, source_content: string} $row
     *
     * @return array{status: string, content: string, error: string|null}
     */
    public function process(array $row): array
    {
        $postId = (int) $row['post_id'];
        $targetLang = (string) $row['target_lang'];
        $sourceContent = (string) $row['source_content'];

        // 1. Build translation messages.
        $messages = $this->promptBuilder->buildMessages($sourceContent, $targetLang);

        $promptFullJson = json_encode($messages, JSON_UNESCAPED_UNICODE) ?: '[]';

        // 2. Stream-translate via SSE, echoing each token to stdout.
        $startTime = hrtime(true);

        try {
            $result = $this->sseClient->translate(
                messages: $messages,
                baseUrl: $this->llmBaseUrl,
                apiKey: $this->llmApiKey,
                model: $this->llmModel,
                timeout: $this->llmTimeout,
                maxRetries: $this->llmMaxRetries,
                onToken: function (string $delta): void {
                    echo $delta;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            );
        } catch (\RuntimeException $e) {
            $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // 3a. Failure — write status=error + log row in a transaction.
            $errorMessage = $e->getMessage();

            $this->db->beginTransaction();
            try {
                $this->db->table('post_translations')
                    ->where('post_id', $postId)
                    ->where('target_lang', $targetLang)
                    ->update([
                        'status' => 'error',
                        'error' => mb_strcut($errorMessage, 0, 65535),
                        'updated_at' => new \DateTimeImmutable(),
                    ]);

                $this->db->table('translation_logs')->insert([
                        'post_id' => $postId,
                        'target_lang' => $targetLang,
                        'prompt_full' => $promptFullJson,
                        'response_final' => $errorMessage,
                        'source_content' => $sourceContent,
                        'translated_content' => '',
                        'tokens_in' => null,
                        'tokens_out' => null,
                        'latency_ms' => $elapsed,
                        'status' => 'error',
                        'error' => mb_strcut($errorMessage, 0, 65535),
                        'created_at' => new \DateTimeImmutable(),
                ]);

                $this->db->commit();
            } catch (\Throwable $dbEx) {
                $this->db->rollBack();
                throw $dbEx;
            }

            echo "\n";

            return [
                'status' => 'error',
                'content' => '',
                'error' => $errorMessage,
            ];
        }

        $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $translatedContent = $result['content'];
        $tokensIn = $result['tokens_in'];
        $tokensOut = $result['tokens_out'];

        // 3b. Success — write status=done + log row in a transaction.
        $this->db->beginTransaction();
        try {
            $this->db->table('post_translations')
                ->where('post_id', $postId)
                ->where('target_lang', $targetLang)
                ->update([
                    'status' => 'done',
                    'translated_content' => $translatedContent,
                    'error' => null,
                    'updated_at' => new \DateTimeImmutable(),
                ]);

            $this->db->table('translation_logs')->insert([
                'post_id' => $postId,
                'target_lang' => $targetLang,
                'prompt_full' => $promptFullJson,
                'response_final' => $translatedContent,
                'source_content' => $sourceContent,
                'translated_content' => $translatedContent,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'latency_ms' => $elapsed,
                'status' => 'done',
                'error' => null,
                'created_at' => new \DateTimeImmutable(),
            ]);

            $this->db->commit();
        } catch (\Throwable $dbEx) {
            $this->db->rollBack();
            throw $dbEx;
        }

        echo "\n";

        return [
            'status' => 'done',
            'content' => $translatedContent,
            'error' => null,
        ];
    }
}
