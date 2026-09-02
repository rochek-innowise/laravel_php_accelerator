<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * BR-006 — a coach is active under exactly one trainer — enforced by the database, not by hope.
     *
     * MariaDB has no partial unique index, so the constraint is expressed as a generated column
     * that is NULL for every non-active row. NULLs do not collide in a unique index, which permits
     * any number of historical `invited`/`inactive` rows while allowing at most one `active` row
     * per coach. This is the schema AD-013 cites as the reason the suite runs on MariaDB rather
     * than SQLite: on SQLite this DDL does not even parse.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE coach_profiles
                ADD COLUMN active_user_id BIGINT UNSIGNED
                    AS (IF(status = 'active', user_id, NULL)) VIRTUAL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE coach_profiles
                ADD UNIQUE INDEX coach_profiles_active_user_id_unique (active_user_id)
        SQL);
    }

    public function down(): void
    {
        // Index first: MariaDB refuses to drop a generated column an index still depends on.
        DB::statement('ALTER TABLE coach_profiles DROP INDEX coach_profiles_active_user_id_unique');
        DB::statement('ALTER TABLE coach_profiles DROP COLUMN active_user_id');
    }
};
