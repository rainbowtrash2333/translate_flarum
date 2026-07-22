<?php

namespace Twikura\Translate\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twikura\Translate\Repository\TranslationLogRepository;

class TranslateLogController implements RequestHandlerInterface
{
    private TranslationLogRepository $logRepo;

    public function __construct(TranslationLogRepository $logRepo)
    {
        $this->logRepo = $logRepo;
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

        $page = (int) ($params['page'] ?? 1);
        if ($page < 1) { $page = 1; }

        $perPage = (int) ($params['perPage'] ?? 50);
        if ($perPage < 1 || $perPage > 200) { $perPage = 50; }

        $lang = isset($params['lang']) ? trim((string) $params['lang']) : null;
        if ($lang === '') { $lang = null; }

        $status = isset($params['status']) ? trim((string) $params['status']) : null;
        if ($status === '') { $status = null; }
        if ($status !== null && ! in_array($status, ['done', 'error'], true)) {
            $status = null;
        }

        $result = $this->logRepo->paginated($page, $perPage, $lang, $status);
        $result['totalPages'] = (int) ceil($result['total'] / $perPage);

        return new JsonResponse($result);
    }
}
