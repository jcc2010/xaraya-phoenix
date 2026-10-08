<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('greetings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 64);
            $t->datetime('created');
            $t->index('created');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('greetings');
    }
};
