<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // first/last are the editable fields of FR-016; the skeleton's `name` column is dropped by the
    // next migration and replaced with an accessor, so there is one source of truth.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->after('email');
            $table->string('status')->default('active')->after('role');
            $table->boolean('is_child_account')->default(false)->after('status');
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable()->after('last_name');
            $table->string('photo_path')->nullable()->after('phone');
            $table->timestamp('last_login_at')->nullable();

            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'status']);
            $table->dropColumn([
                'role',
                'status',
                'is_child_account',
                'first_name',
                'last_name',
                'phone',
                'photo_path',
                'last_login_at',
            ]);
        });
    }
};
