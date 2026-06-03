<?php

namespace Twikura\Translate\Api\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TranslateController extends AbstractTranslateController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($response = $this->ensureGuestAllowed($request)) {
            return $response;
        }

        $body = $this->body($request);
        $lang = $this->validateLang($body['lang'] ?? null);
        $text = $this->validateText($body['text'] ?? '');

        if ($lang === null) {
            return $this->error('A valid target language is required.', 400);
        }

        if ($text === null) {
            return $this->error('Text exceeds the configured maximum length.', 413);
        }

        return $this->proxy('/translate', [
            'lang' => $lang,
            'text' => $text,
        ]);
    }
}
