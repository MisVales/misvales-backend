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
        Schema::create('coordinator_distributor_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coordinator_id')->constrained('users');
            $table->foreignUuid('distributor_id')->constrained('distributors');
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 20);
            $table->foreignUuid('assigned_by')->constrained('users');
            $table->foreignUuid('ended_by')->nullable()->constrained('users');
            $table->text('assignment_reason')->nullable();
            $table->text('end_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();

            // Índices solicitados
            $table->index('coordinator_id');
            $table->index('distributor_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index(['coordinator_id', 'branch_id', 'status']);
        });

        // Índice único parcial para una asignación activa por distribuidora
        DB::statement("CREATE UNIQUE INDEX coord_dist_assign_active_unique ON coordinator_distributor_assignments (distributor_id) WHERE status = 'ACTIVE' AND valid_to IS NULL;");

        // Restricciones a nivel de base de datos
        DB::statement("ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_valid_dates CHECK (valid_to IS NULL OR valid_to > valid_from);");
        DB::statement("ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_active_valid_to CHECK (status != 'ACTIVE' OR valid_to IS NULL);");
        DB::statement("ALTER TABLE coordinator_distributor_assignments ADD CONSTRAINT chk_cda_status CHECK (status IN ('ACTIVE', 'INACTIVE'));");

        // Trigger para evitar eliminación física
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_cda_deletion()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Las asignaciones anteriores no se eliminan físicamente.';
            END;
            $$ LANGUAGE plpgsql;
        ");
        DB::statement("
            CREATE TRIGGER trg_prevent_cda_deletion
            BEFORE DELETE ON coordinator_distributor_assignments
            FOR EACH ROW EXECUTE FUNCTION prevent_cda_deletion();
        ");
        
        /* 
         * Nota: Las reglas de negocio complejas como:
         * - El coordinador debe tener el rol COORDINATOR.
         * - El coordinador debe estar activo.
         * - La distribuidora debe estar activa.
         * - El coordinador y la distribuidora deben pertenecer a la misma sucursal.
         * - Al cambiar de coordinador se debe cerrar la asignación anterior y crear una nueva.
         * Deberán ser gestionadas desde la lógica de la aplicación (Modelos/Servicios)
         */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS trg_prevent_cda_deletion ON coordinator_distributor_assignments;");
        DB::statement("DROP FUNCTION IF EXISTS prevent_cda_deletion();");

        Schema::dropIfExists('coordinator_distributor_assignments');
    }
};
