<?php

declare(strict_types=1);

namespace Twikura\Translate\Command;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Twikura\Translate\Job\Worker;
use Twikura\Translate\Llm\OpenAiSseClient;
use Twikura\Translate\Llm\PromptBuilder;

/**
 * Long-running worker command that polls post_translations for pending rows
 * and processes them one at a time via the Worker.
 *
 * Usage:
 *   php flarum translate:run
 */
final class TranslateRunCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->setName('translate:run')
            ->setDescription('Start the translation worker loop (poll pending rows from post_translations).');
    }

    protected function fire()
    {
        // --- Bootstrap ---------------------------------------------------------

        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $db = resolve(ConnectionInterface::class);
        $settings = resolve(SettingsRepositoryInterface::class);
        $prefix = $db->getTablePrefix();

        // --- Build Worker dependencies -----------------------------------------

        $promptBuilder = resolve(PromptBuilder::class);
        $sseClient = resolve(OpenAiSseClient::class);

        $llmBaseUrl = (string) $settings->get('twikura-translate.llm_base_url', 'http://opencode-go:3000');
        $llmApiKey = (string) $settings->get('twikura-translate.llm_api_key', '');
        $llmModel = (string) $settings->get('twikura-translate.llm_model', 'deepseek-v4-flash');
        $llmTimeout = max(1, (int) $settings->get('twikura-translate.llm_timeout', '120'));
        $llmMaxRetries = max(0, (int) $settings->get('twikura-translate.llm_max_retries', '1'));

        $worker = new Worker(
            promptBuilder: $promptBuilder,
            sseClient: $sseClient,
            db: $db,
            llmBaseUrl: $llmBaseUrl,
            llmApiKey: $llmApiKey,
            llmModel: $llmModel,
            llmTimeout: $llmTimeout,
            llmMaxRetries: $llmMaxRetries,
        );

        // --- Orphan cleanup: reset stuck 'running' rows to 'pending' ----------

        $orphaned = $db->table('post_translations')
            ->where('status', 'running')
            ->update(['status' => 'pending']);

        if ($orphaned > 0) {
            echo "[worker] Reset {$orphaned} orphaned running row(s) to pending.\n";
        }

        // --- Main loop ---------------------------------------------------------

        echo "[worker] translate:run started. Polling every 5s…\n";

        while (true) {
            // Pick the next pending row with atomic row-level lock.
            // Raw query because the query builder's ->lock() doesn't cleanly
            // support "FOR UPDATE SKIP LOCKED" on all database drivers.
            $db->beginTransaction();

            $row = $db->selectOne(
                "SELECT * FROM {$prefix}post_translations"
                . " WHERE status = ?"
                . " ORDER BY is_backfill ASC, created_at ASC"
                . " LIMIT 1"
                . " FOR UPDATE SKIP LOCKED",
                ['pending']
            );

            if ($row === null) {
                $db->rollBack();
                sleep(5);
                continue;
            }

            // Mark as running and release the lock before the long LLM call.
            $db->table('post_translations')
                ->where('post_id', (int) $row->post_id)
                ->where('target_lang', $row->target_lang)
                ->update(['status' => 'running', 'updated_at' => new \DateTimeImmutable()]);

            $db->commit();

            // --- Process the row ------------------------------------------------

            $postId = (int) $row->post_id;
            $targetLang = $row->target_lang;
            $sourceLen = mb_strlen((string) $row->source_content);

            echo "[worker] Translating post#{$postId} → {$targetLang} ({$sourceLen} chars)… ";

            $rowData = [
                'post_id' => $postId,
                'target_lang' => $targetLang,
                'source_content' => $row->source_content,
            ];

            $result = $worker->process($rowData);

            echo "[{$result['status']}]";

            if ($result['status'] === 'error') {
                echo ' ' . mb_substr((string) $result['error'], 0, 120);

                // Rate-limited: back off before the next poll cycle.
                if (str_contains((string) $result['error'], '429')) {
                    echo ' (backing off 30s)';
                    sleep(30);
                }
            } else {
                echo ' (' . mb_strlen($result['content']) . ' chars)';
            }

            echo "\n";
        }

        // Unreachable — the loop runs forever.
        return 0;
    }
}