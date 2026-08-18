<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('assigned_by_user_id')->nullable();
            $table->timestampTz('assigned_at');
            $table->uuid('revoked_by_user_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('revoked_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        $this->crearIndicesUnicosParciales();
    }

    private function crearIndicesUnicosParciales(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('user_role_scopes', function (Blueprint $table) {
                $table->unsignedTinyInteger('legacy_active_global_marker')->nullable()->storedAs('IF(branch_id IS NULL AND revoked_at IS NULL, 1, NULL)');
                $table->unsignedTinyInteger('legacy_active_branch_marker')->nullable()->storedAs('IF(branch_id IS NOT NULL AND revoked_at IS NULL, 1, NULL)');
                $table->unique(['user_id', 'role_id', 'legacy_active_global_marker'], 'user_role_scopes_global_unique');
                $table->unique(['user_id', 'role_id', 'branch_id', 'legacy_active_branch_marker'], 'user_role_scopes_branch_unique');
            });

            return;
        }

        DB::statement('
            CREATE UNIQUE INDEX user_role_scopes_global_unique 
            ON user_role_scopes (user_id, role_id) 
            WHERE branch_id IS NULL AND revoked_at IS NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX user_role_scopes_branch_unique 
            ON user_role_scopes (user_id, role_id, branch_id) 
            WHERE branch_id IS NOT NULL AND revoked_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_scopes');
    }
};
