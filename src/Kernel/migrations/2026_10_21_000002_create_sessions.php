<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/** Sessions keyed by the sha256 of their cookie value (the cookie itself is never stored), with idle and absolute expiry. */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('sessions', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->ulid('user_id')->nullable();
            $t->text('data');
            $t->datetime('last_activity');
            $t->datetime('expires_at');
            // The start of the absolute lifetime (session.max_lifetime): creation, or the user's last login.
            $t->datetime('created_at');
            $t->index('expires_at');
            $t->index('user_id');
            $t->foreign('user_id', 'users');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('sessions');
    }
};
