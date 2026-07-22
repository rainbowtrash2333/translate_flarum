<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('post_translations')) {
            return;
        }

        $schema->create('post_translations', function (Blueprint $table) {
            $table->unsignedInteger('post_id');
            $table->string('target_lang', 10);
            $table->mediumText('source_content');
            $table->mediumText('translated_content')->nullable();
            $table->string('source_lang', 10)->nullable();
            $table->enum('status', ['pending', 'running', 'done', 'error'])->default('pending');
            $table->text('error')->nullable();
            $table->boolean('is_backfill')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['post_id', 'target_lang']);
            $table->index(['status', 'is_backfill', 'created_at'], 'idx_status_backfill_created');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('post_translations');
    },
];
