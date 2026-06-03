<?php

namespace Twikura\Translate\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class AbstractTranslateController
{
    protected SettingsRepositoryInterface $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        if (is_array($body)) {
            return $body;
        }

        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function ensureGuestAllowed(ServerRequestInterface $request): ?ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $allowGuests = (bool) $this->settings->get('twikura-translate.allow_guests', '1');

        if ($actor->isGuest() && ! $allowGuests) {
            return $this->error('Guest translation is disabled.', 403);
        }

        return null;
    }

    protected function maxTextLength(): int
    {
        return max(1, (int) $this->settings->get('twikura-translate.max_text_length', '5000'));
    }

    protected function maxBatchSize(): int
    {
        return max(1, (int) $this->settings->get('twikura-translate.max_batch_size', '20'));
    }

    protected function validateLang($lang): ?string
    {
        $lang = trim((string) $lang);

        if ($lang === '' || mb_strlen($lang) > 32) {
            return null;
        }

        return $lang;
    }

    protected function validateText($text): ?string
    {
        $text = (string) $text;

        if (mb_strlen($text) > $this->maxTextLength()) {
            return null;
        }

        return $text;
    }

    protected function proxy(string $path, array $payload): ResponseInterface
    {
        $baseUrl = rtrim((string) $this->settings->get('twikura-translate.api_base_url', 'http://127.0.0.1:8000'), '/');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return $this->error('Unable to encode translation request.', 400);
        }

        $handle = curl_init($baseUrl.$path);

        if ($handle === false) {
            return $this->error('Unable to initialize translator request.', 502);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        curl_close($handle);

        if ($responseBody === false || $status === 0) {
            return $this->error($error ?: 'Translation service is unavailable.', 502);
        }

        $decoded = json_decode((string) $responseBody, true);

        if (! is_array($decoded)) {
            return $this->error('Translation service returned an invalid response.', 502);
        }

        if ($status >= 400) {
            return $this->error('Translation service rejected the request.', 502, [
                'upstreamStatus' => $status,
                'upstream' => $decoded,
            ]);
        }

        return new JsonResponse($decoded, $status);
    }

    protected function error(string $detail, int $status, array $extra = []): ResponseInterface
    {
        return new JsonResponse([
            'errors' => [
                array_merge([
                    'status' => (string) $status,
                    'detail' => $detail,
                ], $extra),
            ],
        ], $status);
    }
}
