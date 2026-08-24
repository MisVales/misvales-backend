<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->boolean('is_headquarters')->default(false);
            $table->string('status', 20);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('status');
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('branches', function (Blueprint $table): void {
                $table->unsignedTinyInteger('active_headquarters_unique')
                    ->nullable()
                    ->storedAs('IF(is_headquarters = 1, 1, NULL)');
                $table->unique('active_headquarters_unique', 'branches_is_headquarters_unique');
            });

            DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
            DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_lock_version_check CHECK (lock_version >= 0)');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER check_headquarters_deletion
                BEFORE DELETE ON branches
                FOR EACH ROW
                BEGIN
                    IF OLD.is_headquarters = 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La sucursal matriz no puede eliminarse fisicamente.';
                    END IF;
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER check_headquarters_deactivation
                BEFORE UPDATE ON branches
                FOR EACH ROW
                BEGIN
                    IF OLD.is_headquarters = 1 AND NEW.status = 'INACTIVE' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La sucursal matriz no puede desactivarse.';
                    END IF;
                END
            SQL);
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Ãndice Ãºnico parcial para is_headquarters = true
            DB::statement('CREATE UNIQUE INDEX branches_is_headquarters_unique ON branches (is_headquarters) WHERE is_headquarters = true;');

            // RestricciÃ³n: status solo admite ACTIVE o INACTIVE
            DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'));");
            DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_lock_version_check CHECK (lock_version >= 0);');

            // Triggers para proteger la sucursal matriz
            // 1. No puede eliminarse fÃ­sicamente
            if (DB::getDriverName() !== 'sqlite') {
                if (DB::getDriverName() !== 'sqlite') {
                    DB::statement("
                CREATE OR REPLACE FUNCTION prevent_headquarters_deletion()
                RETURNS trigger AS $$
                BEGIN
                    IF OLD.is_headquarters = true THEN
                        RAISE EXCEPTION 'La sucursal matriz no puede eliminarse fÃ­sicamente.';
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
            ");
                    DB::statement('
                CREATE TRIGGER check_headquarters_deletion
                BEFORE DELETE ON branches
                FOR EACH ROW EXECUTE FUNCTION prevent_headquarters_deletion();
            ');
                }
            }

            // 2. No puede desactivarse
            if (DB::getDriverName() !== 'sqlite') {
                if (DB::getDriverName() !== 'sqlite') {
                    DB::statement("
                CREATE OR REPLACE FUNCTION prevent_headquarters_deactivation()
                RETURNS trigger AS $$
                BEGIN
                    IF OLD.is_headquarters = true AND NEW.status = 'INACTIVE' THEN
                        RAISE EXCEPTION 'La sucursal matriz no puede desactivarse.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
                    DB::statement('
                CREATE TRIGGER check_headquarters_deactivation
                BEFORE UPDATE ON branches
                FOR EACH ROW EXECUTE FUNCTION prevent_headquarters_deactivation();
            ');
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP TRIGGER IF EXISTS check_headquarters_deactivation');
            DB::statement('DROP TRIGGER IF EXISTS check_headquarters_deletion');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('DROP TRIGGER IF EXISTS check_headquarters_deactivation ON branches;');
                DB::statement('DROP FUNCTION IF EXISTS prevent_headquarters_deactivation();');
            }

            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('DROP TRIGGER IF EXISTS check_headquarters_deletion ON branches;');
                DB::statement('DROP FUNCTION IF EXISTS prevent_headquarters_deletion();');
            }
        }

        Schema::dropIfExists('branches');
    }
};
