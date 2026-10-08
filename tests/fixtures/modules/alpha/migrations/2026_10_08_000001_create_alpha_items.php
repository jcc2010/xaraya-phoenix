<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('alpha_items', function (Blueprint $t): void {
            $t->increments();
            $t->string('label');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('alpha_items');
    }
};
