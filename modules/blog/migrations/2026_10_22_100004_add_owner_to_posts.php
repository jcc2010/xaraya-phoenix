<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/**
 * Adds posts.owner_id in place, so existing posts, post_tags and post_search rows survive. The column is nullable:
 * mirror-synced posts and posts written by guests have no owner, and deleting a user leaves their posts ownerless.
 *
 * There is no down(): dropping a foreign-key column is not portable across SQLite, MySQL and PostgreSQL without
 * rebuilding the table (Task 12 records the follow-up).
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('posts', function (Blueprint $t): void {
            $t->ulid('owner_id')->nullable();
            $t->foreign('owner_id', 'users', 'id', 'set null');
        });
    }
};
