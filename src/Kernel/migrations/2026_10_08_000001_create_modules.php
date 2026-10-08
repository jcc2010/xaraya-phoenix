<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('modules', function (Blueprint $t): void {
            $t->string('name', 64)->primary();
            $t->string('version', 32);
            $t->bool('enabled')->default(true);
            $t->datetime('installed_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('modules');
    }
};
