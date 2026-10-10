<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('hooks', function (Blueprint $t): void {
            $t->string('observer_module', 64);
            $t->string('subject_module', 64);
            $t->string('itemtype', 64);
            $t->bool('enabled')->default(true);
            $t->primary('observer_module', 'subject_module', 'itemtype');
            $t->index('subject_module', 'itemtype');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('hooks');
    }
};
