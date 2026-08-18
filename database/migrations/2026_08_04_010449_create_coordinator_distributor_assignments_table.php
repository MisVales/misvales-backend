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
        Schema::create('coordinator_distributor_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coordinator_id')->constrained('users')->restrictOnDelete();

            // FK diferida hacia distributors
            $table->uuid('distributor_id');
            $table->index('distributor_id');

            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 20);

            $table->foreignUuid('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('ended_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->text('assignment_reason')->nullable();
            $table->text('end_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();

            // Ãndices de consulta
            $table->index(['coordinator_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['distributor_id', 'status']);
            $table->index(['valid_from', 'valid_to']);
        });

        // 6.6 Ãndices
        // Una distribuidora solo puede tener un coordinador activo:
        DB::statement("
            CREATE UNIQUE INDEX coordinator_distributor_active_distributor_unique
            ON coordinator_distributor_assignments (distributor_id)
            WHERE status = 'ACTIVE' AND valid_to IS NULL;
        ");

        DB::statement("
            CREATE UNIQUE INDEX coordinator_distributor_active_pair_unique
            ON coordinator_distributor_assignments (coordinator_id, distributor_id, branch_id)
            WHERE status = 'ACTIVE' AND valid_to IS NULL;
        ");

        // 6.5 Constraints
        DB::statement("ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_status CHECK (status IN ('ACTIVE', 'ENDED', 'REASSIGNED'));");
        DB::statement('ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_valid_dates CHECK (valid_to IS NULL OR valid_to > valid_from);');
        DB::statement('ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_lock_version CHECK (lock_version >= 0);');
        DB::statement("ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_status_consistency CHECK (
            (status = 'ACTIVE' AND valid_to IS NULL AND ended_by IS NULL)
            OR
            (status IN ('ENDED', 'REASSIGNED') AND valid_to IS NOT NULL)
        );");

        if (DB::getDriverName() !== 'sqlite') {
            if (DB::getDriverName() !== 'sqlite') {
                if (DB::getDriverName() !== 'sqlite') {
                    DB::statement("
                CREATE OR REPLACE FUNCTION prevent_cda_deletion()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Las asignaciones anteriores no se eliminan fÃ­sicamente.';
                END;
                $$ LANGUAGE plpgsql;
            ");
                    DB::statement('
                CREATE TRIGGER trg_prevent_cda_deletion
                BEFORE DELETE ON coordinator_distributor_assignments
                FOR EACH ROW EXECUTE FUNCTION prevent_cda_deletion();
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
        if (DB::getDriverName() !== 'sqlite') {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('DROP TRIGGER IF EXISTS trg_prevent_cda_deletion ON coordinator_distributor_assignments;');
                DB::statement('DROP FUNCTION IF EXISTS prevent_cda_deletion();');
            }
        }

        Schema::dropIfExists('coordinator_distributor_assignments');
    }
};
