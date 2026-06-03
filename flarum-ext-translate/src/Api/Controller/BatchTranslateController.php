<?php

namespace Twikura\Translate\Api\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class BatchTranslateController extends AbstractTranslateController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($response = $this->ensureGuestAllowed($request)) {
            return $response;
        }

        $body = $this->body($request);
        $lang = $this->validateLang($body['lang'] ?? null);
        $items = $body['items'] ?? [];

        if ($lang === null) {
            return $this->error('A valid target language is required.', 400);
        }

        if (! is_array($items)) {
            return $this->error('Items must be an array.', 400);
        }

        if (count($items) > $this->maxBatchSize()) {
            return $this->error('Batch size exceeds the configured maximum.', 413);
        }

        $validated = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                return $this->error("Item {$index} must be an object.", 400);
            }

            $id = trim((string) ($item['id'] ?? ''));
            $text = $this->validateText($item['text'] ?? '');

            if ($id === '' || mb_strlen($id) > 128) {
                return $this->error("Item {$index} has an invalid id.", 400);
            }

            if ($text === null) {
                return $this->error("Item {$index} exceeds the configured maximum length.", 413);
            }

            $validated[] = [
                'id' => $id,
                'text' => $text,
            ];
        }

        return $this->proxy('/translate/batch', [
            'lang' => $lang,
            'items' => $validated,
        ]);
    }
}
