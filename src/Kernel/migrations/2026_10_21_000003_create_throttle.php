<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/** Rate-limit counters; "key" is the sha256 of the throttle key (which holds IP addresses). */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('throttle', function (Blueprint $t): void {
            $t->string('key', 64)->primary();
            $t->int('hits');
            $t->datetime('reset_at');
            $t->index('reset_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('throttle');
    }
};
