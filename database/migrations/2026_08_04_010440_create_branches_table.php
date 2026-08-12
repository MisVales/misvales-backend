<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->boolean('is_headquarters')->default(false);
            $table->boolean('active_headquarters_unique')->virtualAs('IF(is_headquarters = 1, 1, NULL)')->unique();
            $table->string('status', 20);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->index('status');
            $table->index('created_at');
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

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS check_headquarters_deactivation');
        DB::statement('DROP TRIGGER IF EXISTS check_headquarters_deletion');
        Schema::dropIfExists('branches');
    }
};
