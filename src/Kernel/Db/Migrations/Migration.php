<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Migrations;

use Xaraya\Kernel\Db\Schema\Schema;

abstract class Migration
{
    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void {}
}
