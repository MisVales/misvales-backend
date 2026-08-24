<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::create('active_branch_manager_guards', function (Blueprint $table): void {
                $table->foreignUuid('branch_id')->primary()->constrained('branches')->restrictOnDelete();
                $table->foreignUuid('user_role_scope_id')->unique()->constrained('user_role_scopes')->restrictOnDelete();
            });

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trigger_enforce_active_branch_manager_insert
                AFTER INSERT ON user_role_scopes
                FOR EACH ROW
                BEGIN
                    DECLARE EXIT HANDLER FOR 1062
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La sucursal ya cuenta con un gerente activo.';

                    IF NEW.branch_id IS NOT NULL
                       AND NEW.status = 'ACTIVE'
                       AND NEW.revoked_at IS NULL
                       AND EXISTS (SELECT 1 FROM roles WHERE roles.id = NEW.role_id AND roles.code = 'branch_manager') THEN
                        INSERT INTO active_branch_manager_guards (branch_id, user_role_scope_id)
                        VALUES (NEW.branch_id, NEW.id);
                    END IF;
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trigger_enforce_active_branch_manager_update
                AFTER UPDATE ON user_role_scopes
                FOR EACH ROW
                BEGIN
                    DECLARE EXIT HANDLER FOR 1062
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La sucursal ya cuenta con un gerente activo.';

                    DELETE FROM active_branch_manager_guards WHERE user_role_scope_id = OLD.id;

                    IF NEW.branch_id IS NOT NULL
                       AND NEW.status = 'ACTIVE'
                       AND NEW.revoked_at IS NULL
                       AND EXISTS (SELECT 1 FROM roles WHERE roles.id = NEW.role_id AND roles.code = 'branch_manager') THEN
                        INSERT INTO active_branch_manager_guards (branch_id, user_role_scope_id)
                        VALUES (NEW.branch_id, NEW.id);
                    END IF;
                END
            SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_active_branch_manager_cardinality()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.branch_id IS NOT NULL
                   AND NEW.status = 'ACTIVE'
                   AND NEW.revoked_at IS NULL
                   AND EXISTS (
                       SELECT 1 FROM roles
                       WHERE roles.id = NEW.role_id
                         AND roles.code = 'branch_manager'
                   ) THEN
                    PERFORM pg_advisory_xact_lock(hashtext(NEW.branch_id::text));

                    IF EXISTS (
                        SELECT 1
                        FROM user_role_scopes existing
                        JOIN roles ON roles.id = existing.role_id
                        WHERE existing.branch_id = NEW.branch_id
                          AND existing.id <> NEW.id
                          AND existing.status = 'ACTIVE'
                          AND existing.revoked_at IS NULL
                          AND roles.code = 'branch_manager'
                    ) THEN
                        RAISE EXCEPTION 'La sucursal ya cuenta con un gerente activo.'
                            USING ERRCODE = '23505',
                                  CONSTRAINT = 'unique_active_branch_manager';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trigger_enforce_active_branch_manager_cardinality ON user_role_scopes;
            CREATE TRIGGER trigger_enforce_active_branch_manager_cardinality
                BEFORE INSERT OR UPDATE OF branch_id, role_id, status, revoked_at
                ON user_role_scopes
                FOR EACH ROW
                EXECUTE FUNCTION enforce_active_branch_manager_cardinality();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: branch manager cardinality must remain enforced.');
    }
};
