<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/**
 * Tokens no longer need the blog module, so blog_id becomes a plain nullable column. Plan B installs created it with
 * a foreign key to blogs, which cannot be dropped in place on every database (SQLite cannot drop one at all), so the
 * table is rebuilt without it and its rows copied. Blog ids are never reused, so a pin cannot move to another blog.
 * down() keeps the column plain: tokens must stay usable without the blog module.
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('tokens_next', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 64);
            $t->string('token_hash', 64)->unique();
            $t->json('scopes');
            $t->int('blog_id')->nullable();
            $t->datetime('last_used_at')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at');
        });
        $columns = 'id, name, token_hash, scopes, blog_id, last_used_at, expires_at, created_at';
        $schema->connection()->query("INSERT INTO {tokens_next} ({$columns}) SELECT {$columns} FROM {tokens}");
        $schema->drop('tokens');
        $schema->rename('tokens_next', 'tokens');
    }
};
