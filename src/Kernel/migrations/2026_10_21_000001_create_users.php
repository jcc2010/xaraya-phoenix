<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/** Users, roles and grants (kernel spec §4.1), with the seeded roles and the role-wide grants of §4.2. */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('handle', 32)->unique();
            $t->string('name', 255);
            $t->string('email', 254)->unique();
            $t->string('password_hash', 255);
            $t->string('status', 16)->default('active');
            $t->datetime('created');
            $t->datetime('last_login')->nullable();
        });
        $schema->create('roles', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name', 64)->unique();
            $t->int('parent_id')->nullable();
            $t->foreign('parent_id', 'roles', 'id', 'set null');
        });
        $schema->create('user_roles', function (Blueprint $t): void {
            $t->ulid('user_id');
            $t->int('role_id');
            $t->primary('user_id', 'role_id');
            $t->foreign('user_id', 'users');
            $t->foreign('role_id', 'roles');
        });
        $schema->create('grants', function (Blueprint $t): void {
            $t->increments('id');
            $t->int('role_id');
            $t->string('module', 64);
            $t->string('component', 64);
            $t->string('instance', 64);
            $t->int('level');
            $t->unique('role_id', 'module', 'component', 'instance');
            $t->foreign('role_id', 'roles');
        });

        $db = $schema->connection();
        foreach (['Administrators', 'Editors', 'Authors', 'Members', 'Anonymous'] as $name) {
            $db->insert('roles', ['name' => $name]);
        }
        foreach (['Administrators' => 800, 'Anonymous' => 200] as $role => $level) {
            $id = (int) $db->fetchValue('SELECT id FROM {roles} WHERE name = ?', [$role]);
            $db->insert('grants', ['role_id' => $id, 'module' => '*', 'component' => '*', 'instance' => '*', 'level' => $level]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['grants', 'user_roles', 'roles', 'users'] as $table) {
            $schema->drop($table);
        }
    }
};
