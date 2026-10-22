<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/**
 * blog_id pins a token to one blog. It is a plain column, not a foreign key to blogs: tokens work without the blog
 * module. (Plan B installs had the key; 2026_10_21_200000_drop_blog_foreign_key_from_tokens removes it.)
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('tokens', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 64);
            $t->string('token_hash', 64)->unique();
            $t->json('scopes');
            $t->int('blog_id')->nullable();
            $t->datetime('last_used_at')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('tokens');
    }
};
