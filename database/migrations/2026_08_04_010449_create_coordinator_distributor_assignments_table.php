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
        $isMysql = DB::getDriverName() === 'mysql';

        Schema::create('coordinator_distributor_assignments', function (Blueprint $table) use ($isMysql) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coordinator_id')->constrained('users')->restrictOnDelete();

            // FK diferida hacia distributors
            $table->uuid('distributor_id');
            $table->index('distributor_id');

            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 20);
            if ($isMysql) {
                $table->unsignedTinyInteger('active_distributor_unique')->nullable()->storedAs("IF(status = 'ACTIVE' AND valid_to IS NULL, 1, NULL)");
                $table->unsignedTinyInteger('active_pair_unique')->nullable()->storedAs("IF(status = 'ACTIVE' AND valid_to IS NULL, 1, NULL)");
                $table->unique(['distributor_id', 'active_distributor_unique'], 'cda_active_distributor_unique');
                $table->unique(['coordinator_id', 'distributor_id', 'branch_id', 'active_pair_unique'], 'cda_active_pair_unique');
            } else {
                $table->uuid('active_distributor_unique')->nullable()->virtualAs("IF(status = 'ACTIVE' AND valid_to IS NULL, distributor_id, NULL)")->unique();
                $table->string('active_pair_unique', 110)->nullable()->virtualAs("IF(status = 'ACTIVE' AND valid_to IS NULL, CONCAT(coordinator_id, ':', distributor_id, ':', branch_id), NULL)")->unique();
            }

            $table->foreignUuid('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('ended_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->text('assignment_reason')->nullable();
            $table->text('end_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();

            // Índices de consulta
            $table->index(['coordinator_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['distributor_id', 'status']);
            $table->index(['valid_from', 'valid_to']);
        });

        // 6.6 Índices
        // Una distribuidora solo puede tener un coordinador activo:
        // 6.5 Constraints
        DB::statement("ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_status CHECK (status IN ('ACTIVE', 'ENDED', 'REASSIGNED'));");
        DB::statement('ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_valid_dates CHECK (valid_to IS NULL OR valid_to > valid_from);');
        DB::statement('ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_lock_version CHECK (lock_version >= 0);');
        if ($isMysql) {
            DB::statement("ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_status_consistency CHECK (
                (status = 'ACTIVE' AND valid_to IS NULL)
                OR
                (status IN ('ENDED', 'REASSIGNED') AND valid_to IS NOT NULL)
            );");
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_cda_status_consistency_insert
                BEFORE INSERT ON coordinator_distributor_assignments
                FOR EACH ROW
                BEGIN
                    IF NEW.status = 'ACTIVE' AND NEW.ended_by IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una asignacion activa no puede tener finalizador.';
                    END IF;
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_cda_status_consistency_update
                BEFORE UPDATE ON coordinator_distributor_assignments
                FOR EACH ROW
                BEGIN
                    IF NEW.status = 'ACTIVE' AND NEW.ended_by IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una asignacion activa no puede tener finalizador.';
                    END IF;
                END
            SQL);
        } else {
            DB::statement("ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_status_consistency CHECK (
                (status = 'ACTIVE' AND valid_to IS NULL AND ended_by IS NULL)
                OR
                (status IN ('ENDED', 'REASSIGNED') AND valid_to IS NOT NULL)
            );");
        }

        // Trigger para evitar eliminación física
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_prevent_cda_deletion
            BEFORE DELETE ON coordinator_distributor_assignments
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las asignaciones anteriores no se eliminan fisicamente.'
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_prevent_cda_deletion');

        Schema::dropIfExists('coordinator_distributor_assignments');
    }
};
