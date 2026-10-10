<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('settings', function (Blueprint $t): void {
            $t->string('scope', 64);
            $t->string('key', 191);
            $t->json('value');
            $t->primary('scope', 'key');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('settings');
    }
};
