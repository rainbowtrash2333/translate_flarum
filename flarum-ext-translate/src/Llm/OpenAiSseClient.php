<?php

declare(strict_types=1);

namespace Twikura\Translate\Llm;

/**
 * curl SSE streaming client for OpenAI-compatible chat completion APIs.
 *
 * Handles the full lifecycle: connection, SSE parsing, token accumulation,
 * usage extraction, retry logic, and timeout detection.
 *
 * Shared by Worker (background translation) and TranslateTestController (admin test).
 */
final class OpenAiSseClient
{
    /** @var string Accumulated partial SSE event data across chunk boundaries. */
    private string $buffer = '';

    /** @var string Accumulated translated content from all delta chunks. */
    private string $accumulatedContent = '';

    /** @var int|null Prompt tokens extracted from the final chunk's usage field. */
    private ?int $tokensIn = null;

    /** @var int|null Completion tokens extracted from the final chunk's usage field. */
    private ?int $tokensOut = null;

    /** @var bool Whether the SSE stream has signalled [DONE]. */
    private bool $done = false;

    /** @var int HTTP status code from the response. */
    private int $httpCode = 0;

    /** @var string curl error message if any. */
    private string $curlError = '';

    /** @var callable|null Optional callback invoked for each delta content token. */
    private $onToken = null;

    /**
     * Translate messages via SSE streaming chat completion.
     *
     * Retries transient failures (curl errors, 5xx, timeout) up to maxRetries times.
     * 4xx errors propagate immediately without retry.
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @param string $baseUrl          e.g. "http://opencode-go:3000" (no trailing /v1)
     * @param string $apiKey           Bearer token
     * @param string $model            Model ID (e.g. "deepseek-v4-flash")
     * @param int    $timeout          Overall request timeout in seconds
     * @param int    $maxRetries       Maximum retry attempts for transient errors
     * @param callable|null $onToken   Invoked with (string $delta) for each content fragment
     *
     * @return array{content: string, tokens_in: int|null, tokens_out: int|null}
     *
     * @throws \RuntimeException On permanent failure (4xx) or after exhausting retries.
     */
    public function translate(
        array $messages,
        string $baseUrl,
        string $apiKey,
        string $model,
        int $timeout = 120,
        int $maxRetries = 1,
        ?callable $onToken = null
    ): array {
        $totalAttempts = $maxRetries + 1;

        for ($attempt = 1; $attempt <= $totalAttempts; $attempt++) {
            try {
                return $this->doTranslate($messages, $baseUrl, $apiKey, $model, $timeout, $onToken);
            } catch (\RuntimeException $e) {
                $code = $e->getCode();

                // 4xx HTTP errors are non-retryable — propagate immediately.
                if ($code >= 400 && $code < 500) {
                    throw $e;
                }

                // Exhausted retries — propagate the last error.
                if ($attempt >= $totalAttempts) {
                    throw $e;
                }

                // Transient error — retry.
                usleep(min(($attempt ** 2) * 250000, 2000000));
            }
        }

        // Unreachable — satisfy static analysis.
        throw new \RuntimeException('All translation attempts failed');
    }

    /**
     * Execute a single SSE translation request (no internal retry).
     *
     * @throws \RuntimeException With HTTP status code as exception code.
     */
    private function doTranslate(
        array $messages,
        string $baseUrl,
        string $apiKey,
        string $model,
        int $timeout,
        ?callable $onToken
    ): array {
        // Reset state for this request.
        $this->buffer = '';
        $this->accumulatedContent = '';
        $this->tokensIn = null;
        $this->tokensOut = null;
        $this->done = false;
        $this->httpCode = 0;
        $this->curlError = '';
        $this->onToken = $onToken;

        $url = rtrim($baseUrl, '/') . '/v1/chat/completions';

        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0,
            'stream' => true,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new \RuntimeException('Failed to encode translation request payload', 0);
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize curl handle', 0);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => false,   // We handle response via WRITEFUNCTION.
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_WRITEFUNCTION => [$this, 'onChunk'],
            CURLOPT_TIMEOUT => max($timeout, 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 60,
        ]);

        $execResult = curl_exec($ch);
        $this->httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->curlError = curl_error($ch);
        curl_close($ch);

        // --- Error classification -------------------------------------------

        // curl transport error — transient.
        if ($execResult === false || $this->curlError !== '') {
            // Low-speed timeout (CURLE_OPERATION_TIMEDOUT = 28) may also
            // appear here with a message like "Operation timed out after …".
            throw new \RuntimeException(
                'curl transport error: ' . ($this->curlError ?: 'unknown'),
                $this->httpCode ?: 28
            );
        }

        // HTTP 4xx — non-retryable (client error, e.g. bad API key).
        if ($this->httpCode >= 400 && $this->httpCode < 500) {
            throw new \RuntimeException(
                "LLM API returned HTTP {$this->httpCode}",
                $this->httpCode
            );
        }

        // HTTP 5xx — transient (server error).
        if ($this->httpCode >= 500) {
            throw new \RuntimeException(
                "LLM API returned HTTP {$this->httpCode}",
                $this->httpCode
            );
        }

        // If SSE never signalled [DONE], treat the partial result as an error.
        if (! $this->done && $this->accumulatedContent === '') {
            throw new \RuntimeException(
                'SSE stream ended without [DONE] and no content accumulated',
                0
            );
        }

        // Process any remaining partial event in the buffer.
        if ($this->buffer !== '') {
            $this->processEvent($this->buffer);
        }

        return [
            'content' => $this->accumulatedContent,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
        ];
    }

    /**
     * curl CURLOPT_WRITEFUNCTION callback.
     *
     * Receives raw response data, buffers across calls, splits on "\n\n"
     * boundaries to extract complete SSE events, and delegates to processEvent().
     *
     * MUST return strlen($data) so curl knows all bytes were consumed.
     *
     * @param resource $_ch Unused (required by curl API contract).
     * @param string $data Raw bytes from the response stream.
     * @return int Number of bytes consumed.
     */
    private function onChunk($_ch, string $data): int
    {
        $this->buffer .= $data;

        // Extract complete SSE events delimited by double-newline.
        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $event = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);
            $this->processEvent($event);
        }

        return strlen($data);
    }

    /**
     * Parse and handle one complete SSE event block.
     *
     * Each event may contain multiple "data:" lines; we process each one.
     * - "data: [DONE]"  → signals end of stream.
     * - "data: {json}"  → parse JSON, extract choices[0].delta.content,
     *                      and optionally usage.
     *
     * Malformed JSON lines are silently skipped (non-critical).
     */
    private function processEvent(string $eventBlock): void
    {
        $lines = explode("\n", $eventBlock);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty and comment lines.
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            // We only care about "data:" lines.
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            // Strip the "data:" prefix (and optional leading space).
            $payload = trim(substr($line, 5));

            // SSE done signal.
            if ($payload === '[DONE]') {
                $this->done = true;
                return;
            }

            // Parse JSON payload.
            $decoded = json_decode($payload, true);

            if (! is_array($decoded)) {
                error_log('[translate] Malformed SSE JSON chunk skipped: ' . substr($payload, 0, 200));
                continue;
            }

            // Extract usage (typically only in the final chunk).
            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                if (isset($decoded['usage']['prompt_tokens'])) {
                    $this->tokensIn = (int) $decoded['usage']['prompt_tokens'];
                }

                if (isset($decoded['usage']['completion_tokens'])) {
                    $this->tokensOut = (int) $decoded['usage']['completion_tokens'];
                }
            }

            // Extract content delta.
            $delta = $decoded['choices'][0]['delta']['content'] ?? null;

            if ($delta !== null && is_string($delta) && $delta !== '') {
                $this->accumulatedContent .= $delta;

                // Notify the optional token callback.
                if ($this->onToken !== null) {
                    ($this->onToken)($delta);
                }
            }
        }
    }
}
