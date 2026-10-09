<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('blogs', function (Blueprint $t): void {
            $t->increments();
            $t->string('handle', 32)->unique();
            $t->string('title');
            $t->text('description_html')->nullable();
            $t->string('author_name');
            $t->string('author_url', 1024)->nullable();
            $t->string('home_page_url', 1024)->nullable();
            $t->string('post_url_pattern', 1024)->nullable();
            $t->string('language', 16)->default('en');
            $t->string('icon', 1024)->nullable();
            $t->string('favicon', 1024)->nullable();
            $t->string('mode', 16);
            $t->string('source_url', 1024)->nullable();
            $t->string('source_format', 32)->nullable();
            $t->string('media', 16)->default('remote');
            $t->string('pinned_item_id', 512)->nullable();
            $t->string('source_etag')->nullable();
            $t->datetime('last_synced_at')->nullable();
            $t->datetime('last_full_sync_at')->nullable();
            $t->json('extra')->nullable();
            $t->datetime('created_at');
            $t->datetime('updated_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('blogs');
    }
};
