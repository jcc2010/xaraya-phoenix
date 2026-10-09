<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->int('blog_id');
            $t->string('item_id', 512);
            $t->string('kind', 16);
            $t->text('title');
            $t->string('url', 1024)->nullable();
            $t->string('external_url', 2048)->nullable();
            $t->text('content_html')->nullable();
            $t->string('image', 2048)->nullable();
            $t->datetime('date_published');
            $t->datetime('date_modified')->nullable();
            $t->string('status', 16);
            $t->string('source_hash', 40)->nullable();
            $t->json('doc')->nullable();
            $t->datetime('created_at');
            $t->datetime('updated_at');
            $t->foreign('blog_id', 'blogs');
            $t->unique('blog_id', 'item_id');
            $t->index('blog_id', 'status', 'date_published', 'id');
        });
        $schema->create('post_tags', function (Blueprint $t): void {
            $t->ulid('post_id');
            $t->int('blog_id');
            $t->int('position');
            $t->string('name', 128);
            $t->string('slug', 128);
            $t->primary('post_id', 'slug');
            $t->foreign('post_id', 'posts');
            $t->index('blog_id', 'slug');
        });
        $schema->create('media', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->int('blog_id');
            $t->string('source_url', 2048)->nullable();
            $t->string('source_url_hash', 40)->nullable();
            $t->string('path', 512);
            $t->string('mime', 64);
            $t->bigint('bytes');
            $t->string('sha256', 64);
            $t->int('width')->nullable();
            $t->int('height')->nullable();
            $t->datetime('created_at');
            $t->foreign('blog_id', 'blogs');
            $t->unique('blog_id', 'source_url_hash');
            $t->index('blog_id', 'sha256');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('media');
        $schema->drop('post_tags');
        $schema->drop('posts');
    }
};
