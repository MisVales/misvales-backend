<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('role_id');
                $table->uuid('permission_id');
                $table->uuid('granted_by_user_id')->nullable();
                $table->timestampTz('granted_at');
                $table->uuid('revoked_by_user_id')->nullable();
                $table->timestampTz('revoked_at')->nullable();
                $table->string('revocation_reason')->nullable();

                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
                $table->foreign('granted_by_user_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('revoked_by_user_id')->references('id')->on('users')->onDelete('set null');
            });
        }

        $this->crearIndiceUnicoParcial();
    }

    private function crearIndiceUnicoParcial(): void
    {
        if (Schema::hasIndex('role_permissions', 'role_permissions_active_unique')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            Schema::table('role_permissions', function (Blueprint $table) {
                if (! Schema::hasColumn('role_permissions', 'active_unique')) {
                    $table->unsignedTinyInteger('active_unique')->nullable()->storedAs('IF(revoked_at IS NULL, 1, NULL)');
                }
                $table->unique(['role_id', 'permission_id', 'active_unique'], 'role_permissions_active_unique');
            });

            return;
        }

        DB::statement('
            CREATE UNIQUE INDEX role_permissions_active_unique 
            ON role_permissions (role_id, permission_id) 
            WHERE revoked_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
