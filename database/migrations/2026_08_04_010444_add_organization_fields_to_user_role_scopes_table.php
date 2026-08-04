<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_role_scopes', function (Blueprint $table) {
            // 1. Eliminar los índices únicos de la migración anterior
            $table->dropIndex('user_role_scopes_global_unique');
            $table->dropIndex('user_role_scopes_branch_unique');

            // 2. Eliminar foreign keys viejas
            $table->dropForeign(['assigned_by_user_id']);
            $table->dropForeign(['revoked_by_user_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['role_id']);
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            // 3. Transformar (renombrar) columnas de auditoría
            $table->renameColumn('assigned_by_user_id', 'assigned_by');
            $table->renameColumn('assigned_at', 'valid_from');
            $table->renameColumn('revoked_by_user_id', 'ended_by');
            $table->renameColumn('revoked_at', 'valid_to');
            $table->renameColumn('revocation_reason', 'reason');
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            // 4. Nuevas columnas (primero como nullable)
            $table->string('scope_type', 20)->nullable();
            $table->string('status', 20)->nullable();
            $table->timestampsTz();
        });

        // 5. Actualizar datos (si existieran) para cumplir con constraints not null
        DB::statement("UPDATE user_role_scopes SET scope_type = 'GLOBAL' WHERE branch_id IS NULL AND scope_type IS NULL;");
        DB::statement("UPDATE user_role_scopes SET scope_type = 'BRANCH' WHERE branch_id IS NOT NULL AND scope_type IS NULL;");
        DB::statement("UPDATE user_role_scopes SET status = 'ACTIVE' WHERE valid_to IS NULL AND status IS NULL;");
        DB::statement("UPDATE user_role_scopes SET status = 'REVOKED' WHERE valid_to IS NOT NULL AND status IS NULL;");

        Schema::table('user_role_scopes', function (Blueprint $table) {
            // 6. Volver strictas las nuevas columnas
            $table->string('scope_type', 20)->nullable(false)->change();
            $table->string('status', 20)->nullable(false)->change();

            // 7. Foreign keys definitivas con restrictOnDelete
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();

            // 8. Índices de consulta solicitados
            $table->index(['user_id', 'status']);
            $table->index(['role_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['scope_type', 'status']);
        });

        // 9. Índices parciales requeridos
        DB::statement("
            CREATE UNIQUE INDEX user_role_scopes_active_global_unique
            ON user_role_scopes (user_id, role_id, scope_type)
            WHERE status = 'ACTIVE'
              AND valid_to IS NULL
              AND branch_id IS NULL;
        ");

        DB::statement("
            CREATE UNIQUE INDEX user_role_scopes_active_branch_unique
            ON user_role_scopes (user_id, role_id, branch_id, scope_type)
            WHERE status = 'ACTIVE'
              AND valid_to IS NULL
              AND branch_id IS NOT NULL;
        ");

        // 10. Checks requeridos
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_type CHECK (scope_type IN ('GLOBAL', 'BRANCH'));");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_urs_status CHECK (status IN ('ACTIVE', 'ENDED', 'REVOKED'));");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_branch_match CHECK (
            (scope_type = 'GLOBAL' AND branch_id IS NULL) OR 
            (scope_type = 'BRANCH' AND branch_id IS NOT NULL)
        );");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_valid_dates CHECK (valid_to IS NULL OR valid_to > valid_from);");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_status_consistency CHECK (
            (status = 'ACTIVE' AND valid_to IS NULL AND ended_by IS NULL) OR 
            (status IN ('ENDED', 'REVOKED') AND valid_to IS NOT NULL)
        );");

        // 11. Trigger para evitar eliminación física
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_urs_deletion()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'No se deben eliminar físicamente asignaciones anteriores.';
            END;
            $$ LANGUAGE plpgsql;
        ");
        DB::statement("
            CREATE TRIGGER trg_prevent_urs_deletion
            BEFORE DELETE ON user_role_scopes
            FOR EACH ROW EXECUTE FUNCTION prevent_urs_deletion();
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS trg_prevent_urs_deletion ON user_role_scopes;");
        DB::statement("DROP FUNCTION IF EXISTS prevent_urs_deletion();");

        Schema::table('user_role_scopes', function (Blueprint $table) {
            DB::statement("ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_status_consistency;");
            DB::statement("ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_valid_dates;");
            DB::statement("ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_scope_branch_match;");
            DB::statement("ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_urs_status;");
            DB::statement("ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_scope_type;");

            DB::statement("DROP INDEX IF EXISTS user_role_scopes_active_global_unique;");
            DB::statement("DROP INDEX IF EXISTS user_role_scopes_active_branch_unique;");
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['assigned_by']);
            $table->dropForeign(['ended_by']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['role_id']);

            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['role_id', 'status']);
            $table->dropIndex(['branch_id', 'status']);
            $table->dropIndex(['scope_type', 'status']);
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->renameColumn('assigned_by', 'assigned_by_user_id');
            $table->renameColumn('valid_from', 'assigned_at');
            $table->renameColumn('ended_by', 'revoked_by_user_id');
            $table->renameColumn('valid_to', 'revoked_at');
            $table->renameColumn('reason', 'revocation_reason');
            
            $table->dropColumn(['scope_type', 'status', 'created_at', 'updated_at']);
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('revoked_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        });

        DB::statement('CREATE UNIQUE INDEX user_role_scopes_global_unique ON user_role_scopes (user_id, role_id) WHERE branch_id IS NULL AND revoked_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX user_role_scopes_branch_unique ON user_role_scopes (user_id, role_id, branch_id) WHERE branch_id IS NOT NULL AND revoked_at IS NULL');
    }
};
