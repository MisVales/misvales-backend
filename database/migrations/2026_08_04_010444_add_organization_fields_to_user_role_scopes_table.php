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
            if (DB::getDriverName() === 'mysql') {
                // Preserve an index for FK user_id before dropping the legacy composite unique index.
                $table->index('user_id', 'user_role_scopes_user_id_idx');
                $table->index('role_id', 'user_role_scopes_role_id_idx');
            }

            $table->dropIndex('user_role_scopes_global_unique');
            $table->dropIndex('user_role_scopes_branch_unique');

            $table->string('scope_type', 20)->nullable();
            $table->string('status', 20)->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('user_role_scopes', function (Blueprint $table): void {
                $table->unsignedTinyInteger('active_global_unique')
                    ->nullable()
                    ->storedAs("IF(status = 'ACTIVE' AND revoked_at IS NULL AND scope_type = 'GLOBAL', 1, NULL)");
                $table->unsignedTinyInteger('active_branch_unique')
                    ->nullable()
                    ->storedAs("IF(status = 'ACTIVE' AND revoked_at IS NULL AND branch_id IS NOT NULL, 1, NULL)");
                $table->unique(['user_id', 'role_id', 'active_global_unique'], 'user_role_scopes_active_global_unique');
                $table->unique(['user_id', 'role_id', 'branch_id', 'active_branch_unique'], 'user_role_scopes_active_branch_unique');
                $table->dropColumn(['legacy_active_global_marker', 'legacy_active_branch_marker']);
            });
        }

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

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_role_scopes_active_global_unique
            ON user_role_scopes (user_id, role_id, scope_type)
            WHERE status = 'ACTIVE'
              AND revoked_at IS NULL
              AND scope_type = 'GLOBAL';
        SQL);

            DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_role_scopes_active_branch_unique
            ON user_role_scopes (user_id, role_id, branch_id, scope_type)
            WHERE status = 'ACTIVE'
              AND revoked_at IS NULL
              AND branch_id IS NOT NULL
        SQL);

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

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_urs_deletion()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'No se deben eliminar fÃ­sicamente asignaciones anteriores.';
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement('
                CREATE TRIGGER trg_prevent_urs_deletion
                BEFORE DELETE ON user_role_scopes
                FOR EACH ROW EXECUTE FUNCTION prevent_urs_deletion();
            ');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_type CHECK (scope_type IN ('GLOBAL', 'BRANCH'))");
            DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_urs_status CHECK (status IN ('ACTIVE', 'ENDED', 'REVOKED'))");
            DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_branch_match CHECK ((scope_type = 'GLOBAL') OR (scope_type = 'BRANCH' AND branch_id IS NOT NULL))");
            DB::statement('ALTER TABLE user_role_scopes ADD CONSTRAINT chk_valid_dates CHECK (revoked_at IS NULL OR revoked_at > assigned_at)');
            DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_status_consistency CHECK ((status = 'ACTIVE' AND revoked_at IS NULL) OR (status IN ('ENDED', 'REVOKED') AND revoked_at IS NOT NULL))");
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_urs_status_consistency_insert
                BEFORE INSERT ON user_role_scopes
                FOR EACH ROW
                BEGIN
                    IF NEW.status = 'ACTIVE' AND NEW.revoked_by_user_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una asignacion activa no puede tener revocador.';
                    END IF;
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_urs_status_consistency_update
                BEFORE UPDATE ON user_role_scopes
                FOR EACH ROW
                BEGIN
                    IF NEW.status = 'ACTIVE' AND NEW.revoked_by_user_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una asignacion activa no puede tener revocador.';
                    END IF;
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_prevent_urs_deletion
                BEFORE DELETE ON user_role_scopes
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se deben eliminar fisicamente asignaciones anteriores.'
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_prevent_urs_deletion');
            DB::statement('DROP TRIGGER IF EXISTS trg_urs_status_consistency_update');
            DB::statement('DROP TRIGGER IF EXISTS trg_urs_status_consistency_insert');

            foreach (['chk_status_consistency', 'chk_valid_dates', 'chk_scope_branch_match', 'chk_urs_status', 'chk_scope_type'] as $constraint) {
                DB::statement("ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_prevent_urs_deletion ON user_role_scopes;');
            DB::statement('DROP FUNCTION IF EXISTS prevent_urs_deletion();');

            foreach (['chk_status_consistency', 'chk_valid_dates', 'chk_scope_branch_match', 'chk_urs_status', 'chk_scope_type'] as $constraint) {
                DB::statement("ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS {$constraint}");
            }

            DB::statement('DROP INDEX IF EXISTS user_role_scopes_active_global_unique;');
            DB::statement('DROP INDEX IF EXISTS user_role_scopes_active_branch_unique;');
        } elseif ($driver === 'mysql') {
            Schema::table('user_role_scopes', function (Blueprint $table): void {
                $table->dropUnique('user_role_scopes_active_global_unique');
                $table->dropUnique('user_role_scopes_active_branch_unique');
                $table->dropColumn(['active_global_unique', 'active_branch_unique']);
            });
        }

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['role_id', 'status']);
            $table->dropIndex(['branch_id', 'status']);
            $table->dropIndex(['scope_type', 'status']);
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->dropColumn(['scope_type', 'status', 'created_at', 'updated_at']);
        });

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX user_role_scopes_global_unique ON user_role_scopes (user_id, role_id) WHERE branch_id IS NULL AND revoked_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX user_role_scopes_branch_unique ON user_role_scopes (user_id, role_id, branch_id) WHERE branch_id IS NOT NULL AND revoked_at IS NULL');
        } elseif ($driver === 'mysql') {
            Schema::table('user_role_scopes', function (Blueprint $table): void {
                $table->unsignedTinyInteger('legacy_active_global_marker')->nullable()->storedAs('IF(branch_id IS NULL AND revoked_at IS NULL, 1, NULL)');
                $table->unsignedTinyInteger('legacy_active_branch_marker')->nullable()->storedAs('IF(branch_id IS NOT NULL AND revoked_at IS NULL, 1, NULL)');
                $table->unique(['user_id', 'role_id', 'legacy_active_global_marker'], 'user_role_scopes_global_unique');
                $table->unique(['user_id', 'role_id', 'branch_id', 'legacy_active_branch_marker'], 'user_role_scopes_branch_unique');
                $table->dropIndex('user_role_scopes_user_id_idx');
                $table->dropIndex('user_role_scopes_role_id_idx');
            });
        }
    }
};
