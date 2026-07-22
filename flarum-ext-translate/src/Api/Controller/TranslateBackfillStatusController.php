<?php

namespace Twikura\Translate\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twikura\Translate\Repository\PostTranslationRepository;

class TranslateBackfillStatusController implements RequestHandlerInterface
{
    private PostTranslationRepository $postTranslationRepo;

    public function __construct(PostTranslationRepository $postTranslationRepo)
    {
        $this->postTranslationRepo = $postTranslationRepo;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! $actor->isAdmin()) {
            return new JsonResponse([
                'errors' => [
                    ['status' => '403', 'detail' => 'Permission denied.'],
                ],
            ], 403);
        }

        $params = $request->getQueryParams();
        $lang = trim((string) ($params['lang'] ?? ''));

        if ($lang === '') {
            return new JsonResponse([
                'errors' => [
                    ['status' => '400', 'detail' => 'Query parameter "lang" is required.'],
                ],
            ], 400);
        }

        $counts = $this->postTranslationRepo->getStatusCounts($lang);

        return new JsonResponse($counts);
    }
}
