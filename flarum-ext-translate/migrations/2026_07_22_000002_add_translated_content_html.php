<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('post_translations') && $schema->hasColumn('post_translations', 'translated_content_html')) {
            return;
        }

        $schema->table('post_translations', function (Blueprint $table) {
            $table->mediumText('translated_content_html')->nullable()->after('translated_content');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('post_translations', function (Blueprint $table) {
            $table->dropColumn('translated_content_html');
        });
    },
];
