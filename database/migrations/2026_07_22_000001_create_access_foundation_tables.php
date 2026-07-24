<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->boolean('is_headquarters')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('scope', 10);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->after('password')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->after('role_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('context_version')->default(1)->after('branch_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('GLOBAL', 'BRANCH'));
                CREATE UNIQUE INDEX branches_single_headquarters ON branches (is_headquarters) WHERE is_headquarters = true;

                CREATE OR REPLACE FUNCTION validate_user_organizational_context() RETURNS trigger AS $$
                DECLARE role_scope varchar(10);
                DECLARE branch_active boolean;
                BEGIN
                    IF TG_OP = 'UPDATE' AND NEW.role_id IS DISTINCT FROM OLD.role_id THEN
                        RAISE EXCEPTION 'A user role is immutable';
                    END IF;
                    SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id AND is_active = true;
                    IF role_scope IS NULL THEN RAISE EXCEPTION 'An active role is required'; END IF;
                    IF role_scope = 'GLOBAL' AND NEW.branch_id IS NOT NULL THEN RAISE EXCEPTION 'A global role cannot belong to a branch'; END IF;
                    IF role_scope = 'BRANCH' AND NEW.branch_id IS NULL THEN RAISE EXCEPTION 'A branch role requires a branch'; END IF;
                    IF role_scope = 'BRANCH' THEN
                        SELECT is_active INTO branch_active FROM branches WHERE id = NEW.branch_id;
                        IF branch_active IS DISTINCT FROM true THEN RAISE EXCEPTION 'A branch role requires an active branch'; END IF;
                    END IF;
                    IF TG_OP = 'UPDATE' AND NEW.branch_id IS DISTINCT FROM OLD.branch_id THEN
                        NEW.context_version := OLD.context_version + 1;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER users_organizational_context
                BEFORE INSERT OR UPDATE OF role_id, branch_id ON users
                FOR EACH ROW EXECUTE FUNCTION validate_user_organizational_context();

                CREATE OR REPLACE FUNCTION protect_access_catalogs_and_branches() RETURNS trigger AS $$
                BEGIN
                    IF TG_TABLE_NAME IN ('roles', 'permissions') AND NEW.code IS DISTINCT FROM OLD.code THEN
                        RAISE EXCEPTION 'Stable catalog codes are immutable';
                    END IF;
                    IF TG_TABLE_NAME = 'roles' AND (NEW.scope IS DISTINCT FROM OLD.scope OR NEW.is_active IS DISTINCT FROM OLD.is_active)
                       AND EXISTS (SELECT 1 FROM users WHERE role_id = OLD.id) THEN
                        RAISE EXCEPTION 'An assigned role scope or state cannot change';
                    END IF;
                    IF TG_TABLE_NAME = 'branches' AND NEW.is_active = false AND OLD.is_active = true
                       AND EXISTS (SELECT 1 FROM users WHERE branch_id = OLD.id) THEN
                        RAISE EXCEPTION 'An assigned branch cannot be deactivated';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER protect_roles BEFORE UPDATE ON roles FOR EACH ROW EXECUTE FUNCTION protect_access_catalogs_and_branches();
                CREATE TRIGGER protect_permissions BEFORE UPDATE ON permissions FOR EACH ROW EXECUTE FUNCTION protect_access_catalogs_and_branches();
                CREATE TRIGGER protect_branches BEFORE UPDATE OF is_active ON branches FOR EACH ROW EXECUTE FUNCTION protect_access_catalogs_and_branches();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_access_catalogs_and_branches() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS validate_user_organizational_context() CASCADE');
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('context_version');
        });
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('branches');
    }
};
