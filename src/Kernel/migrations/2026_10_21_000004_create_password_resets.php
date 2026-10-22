<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/** Password reset links: only the sha256 of each link's token is stored. */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('password_resets', function (Blueprint $t): void {
            $t->string('token_hash', 64)->primary();
            $t->ulid('user_id');
            $t->datetime('expires_at');
            $t->datetime('created_at');
            $t->index('user_id');
            $t->foreign('user_id', 'users');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('password_resets');
    }
};
