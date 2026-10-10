<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('blocks', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('type', 128);
            $t->string('region', 64);
            $t->string('title', 255)->nullable();
            $t->json('config');
            $t->int('sort')->default(0);
            $t->json('visibility');
            $t->bool('enabled')->default(true);
            $t->index('region', 'sort');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('blocks');
    }
};
