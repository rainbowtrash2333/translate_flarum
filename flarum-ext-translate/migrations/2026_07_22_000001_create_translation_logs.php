<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('translation_logs')) {
            return;
        }

        $schema->create('translation_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('post_id');
            $table->string('target_lang', 10);
            $table->mediumText('prompt_full');
            $table->mediumText('response_final');
            $table->mediumText('source_content');
            $table->mediumText('translated_content');
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->enum('status', ['done', 'error']);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('post_id', 'idx_post');
            $table->index('created_at', 'idx_created');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('translation_logs');
    },
];
