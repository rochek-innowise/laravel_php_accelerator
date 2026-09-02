<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // FR-016 edits first_name/last_name; `name` becomes an accessor so there is one source of truth.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Nullable: the dropped values are gone, and a NOT NULL column would silently
            // backfill empty strings rather than admit the rollback cannot restore them.
            $table->string('name')->nullable()->after('id');
        });
    }
};
