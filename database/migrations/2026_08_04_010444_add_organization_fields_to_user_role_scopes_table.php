<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->dropIndex('user_role_scopes_global_unique');
            $table->dropIndex('user_role_scopes_branch_unique');

            $table->string('scope_type', 20)->nullable();
            $table->string('status', 20)->nullable();
            $table->string('active_global_unique', 80)->nullable()->virtualAs("IF(status = 'ACTIVE' AND revoked_at IS NULL AND scope_type = 'GLOBAL', CONCAT(user_id, ':', role_id), NULL)")->unique();
            $table->string('active_branch_unique', 120)->nullable()->virtualAs("IF(status = 'ACTIVE' AND revoked_at IS NULL AND branch_id IS NOT NULL, CONCAT(user_id, ':', role_id, ':', branch_id), NULL)")->unique();
            $table->timestampsTz();
        });

        // Keep the Module 01 columns as the canonical assignment lifecycle:
        // assigned_at, revoked_at, assigned_by_user_id, revoked_by_user_id and
        // revocation_reason.
        DB::statement("UPDATE user_role_scopes SET scope_type = CASE WHEN branch_id IS NULL THEN 'GLOBAL' ELSE 'BRANCH' END WHERE scope_type IS NULL");
        DB::statement("UPDATE user_role_scopes SET status = CASE WHEN revoked_at IS NULL THEN 'ACTIVE' ELSE 'REVOKED' END WHERE status IS NULL");
        DB::statement('UPDATE user_role_scopes SET created_at = assigned_at WHERE created_at IS NULL');
        DB::statement('UPDATE user_role_scopes SET updated_at = COALESCE(revoked_at, assigned_at) WHERE updated_at IS NULL');

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->string('scope_type', 20)->nullable(false)->change();
            $table->string('status', 20)->nullable(false)->change();

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->index(['user_id', 'status']);
            $table->index(['role_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['scope_type', 'status']);
        });

        // 10. Checks requeridos
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_type CHECK (scope_type IN ('GLOBAL', 'BRANCH'));");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_urs_status CHECK (status IN ('ACTIVE', 'ENDED', 'REVOKED'));");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_branch_match CHECK (
            (scope_type = 'GLOBAL') OR 
            (scope_type = 'BRANCH' AND branch_id IS NOT NULL)
        );");
        DB::statement('ALTER TABLE user_role_scopes ADD CONSTRAINT chk_valid_dates CHECK (revoked_at IS NULL OR revoked_at > assigned_at);');
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_status_consistency CHECK (
            (status = 'ACTIVE' AND revoked_at IS NULL AND revoked_by_user_id IS NULL) OR 
            (status IN ('ENDED', 'REVOKED') AND revoked_at IS NOT NULL)
        );");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_prevent_urs_deletion
            BEFORE DELETE ON user_role_scopes
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se deben eliminar fisicamente asignaciones anteriores.'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_prevent_urs_deletion');

        Schema::table('user_role_scopes', function (Blueprint $table) {
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_status_consistency;');
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_valid_dates;');
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_scope_branch_match;');
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_urs_status;');
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_scope_type;');

            DB::statement('DROP INDEX IF EXISTS user_role_scopes_active_global_unique;');
            DB::statement('DROP INDEX IF EXISTS user_role_scopes_active_branch_unique;');
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
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

    }
};
