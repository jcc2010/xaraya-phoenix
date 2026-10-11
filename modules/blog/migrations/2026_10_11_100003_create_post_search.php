<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('post_search', function (Blueprint $t): void {
            $t->ulid('post_id')->primary();
            $t->int('blog_id');
            $t->text('body');
            $t->foreign('post_id', 'posts');
            $t->index('blog_id');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('post_search');
    }
};
