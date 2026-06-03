<?php

use Flarum\Extend;
use Twikura\Translate\Api\Controller\BatchTranslateController;
use Twikura\Translate\Api\Controller\TranslateController;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    (new Extend\Routes('api'))
        ->post('/translate', 'twikura-translate.translate', TranslateController::class)
        ->post('/translate/batch', 'twikura-translate.translate-batch', BatchTranslateController::class),

    (new Extend\Settings())
        ->default('twikura-translate.api_base_url', 'http://127.0.0.1:8000')
        ->default('twikura-translate.allow_guests', '1')
        ->default('twikura-translate.max_text_length', '5000')
        ->default('twikura-translate.max_batch_size', '20')
        ->serializeToForum('twikuraTranslateAllowGuests', 'twikura-translate.allow_guests', 'boolval', true)
        ->serializeToForum('twikuraTranslateMaxTextLength', 'twikura-translate.max_text_length', 'intval', 5000)
        ->serializeToForum('twikuraTranslateMaxBatchSize', 'twikura-translate.max_batch_size', 'intval', 20),

    new Extend\Locales(__DIR__.'/locale'),
];
