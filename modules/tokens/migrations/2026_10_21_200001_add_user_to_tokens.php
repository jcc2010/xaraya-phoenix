<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/**
 * Adds tokens.user_id (FK users, deleted with the user). A column with a foreign key cannot be added in place on
 * every database, so the table is rebuilt and its rows copied; down() rebuilds it without the column. blog_id stays
 * a plain column (2026_10_21_200000).
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $this->rebuild($schema, true);
    }

    public function down(Schema $schema): void
    {
        $this->rebuild($schema, false);
    }

    private function rebuild(Schema $schema, bool $withUser): void
    {
        $schema->create('tokens_next', function (Blueprint $t) use ($withUser): void {
            $t->ulid('id')->primary();
            $t->string('name', 64);
            $t->string('token_hash', 64)->unique();
            $t->json('scopes');
            $t->int('blog_id')->nullable();
            if ($withUser) {
                $t->ulid('user_id')->nullable();
            }
            $t->datetime('last_used_at')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at');
            if ($withUser) {
                $t->foreign('user_id', 'users');
            }
        });
        $columns = 'id, name, token_hash, scopes, blog_id, last_used_at, expires_at, created_at';
        $schema->connection()->query("INSERT INTO {tokens_next} ({$columns}) SELECT {$columns} FROM {tokens}");
        $schema->drop('tokens');
        $schema->rename('tokens_next', 'tokens');
    }
};
