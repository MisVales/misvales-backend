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
            // Eliminar los índices únicos de la migración anterior
            $table->dropUnique('user_role_scopes_global_unique');
            $table->dropUnique('user_role_scopes_branch_unique');

            // Eliminar foreign keys de columnas a remover
            $table->dropForeign(['assigned_by_user_id']);
            $table->dropForeign(['revoked_by_user_id']);

            // Eliminar columnas previas que no se usarán
            $table->dropColumn([
                'branch_id',
                'assigned_by_user_id',
                'assigned_at',
                'revoked_by_user_id',
                'revoked_at',
                'revocation_reason'
            ]);

            // Agregar campos organizacionales
            $table->uuid('branch_id')->nullable();
            $table->string('scope_type', 20);
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 20);
            $table->uuid('assigned_by');
            $table->text('reason')->nullable();
            $table->timestampsTz();
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            // Foreign keys
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('assigned_by')->references('id')->on('users');

            // Índices solicitados
            $table->index('user_id');
            $table->index('role_id');
            $table->index('branch_id');
            $table->index('scope_type');
            $table->index('status');
            $table->index(['user_id', 'role_id', 'branch_id']);
        });

        // Índice parcial para asignaciones activas
        DB::statement("CREATE UNIQUE INDEX user_role_scopes_active_partial_idx ON user_role_scopes (user_id, role_id) WHERE status = 'ACTIVE' AND valid_to IS NULL;");

        // Restricciones de validación
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_global CHECK (scope_type != 'GLOBAL' OR branch_id IS NULL);");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_branch CHECK (scope_type != 'BRANCH' OR branch_id IS NOT NULL);");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_assigned CHECK (scope_type != 'ASSIGNED' OR branch_id IS NOT NULL);");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_dates CHECK (valid_to IS NULL OR valid_to > valid_from);");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_active_valid_to CHECK (status != 'ACTIVE' OR valid_to IS NULL);");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_urs_scope_type CHECK (scope_type IN ('GLOBAL', 'BRANCH', 'ASSIGNED', 'SELF'));");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_urs_status CHECK (status IN ('ACTIVE', 'INACTIVE'));");

        // Trigger para evitar eliminación física
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
        
        /* 
         * Nota: Las reglas de negocio complejas como:
         * - Al cambiar de sucursal se debe cerrar la asignación activa y crear una nueva.
         * - Un gerente de sucursal solo puede tener una sucursal activa. (Parcialmente cubierta por el índice)
         * - No se puede asignar una sucursal inactiva.
         * - No se puede asignar personal deshabilitado.
         * Deberán ser gestionadas desde la lógica de la aplicación (Modelos/Servicios) 
         * o a través de triggers más complejos consultando las otras tablas.
         */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS trg_prevent_urs_deletion ON user_role_scopes;");
        DB::statement("DROP FUNCTION IF EXISTS prevent_urs_deletion();");

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['assigned_by']);
            
            $table->dropIndex('user_role_scopes_user_id_index');
            $table->dropIndex('user_role_scopes_role_id_index');
            $table->dropIndex('user_role_scopes_branch_id_index');
            $table->dropIndex('user_role_scopes_scope_type_index');
            $table->dropIndex('user_role_scopes_status_index');
            $table->dropIndex(['user_id', 'role_id', 'branch_id']);
            
            $table->dropColumn([
                'branch_id',
                'scope_type',
                'valid_from',
                'valid_to',
                'status',
                'assigned_by',
                'reason',
                'created_at',
                'updated_at'
            ]);

            // Revertir a las columnas antiguas
            $table->uuid('branch_id')->nullable();
            $table->uuid('assigned_by_user_id')->nullable();
            $table->timestampTz('assigned_at')->nullable(); // nullable para revertir sin error
            $table->uuid('revoked_by_user_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
        });

        Schema::table('user_role_scopes', function (Blueprint $table) {
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('revoked_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement('CREATE UNIQUE INDEX user_role_scopes_global_unique ON user_role_scopes (user_id, role_id) WHERE branch_id IS NULL AND revoked_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX user_role_scopes_branch_unique ON user_role_scopes (user_id, role_id, branch_id) WHERE branch_id IS NOT NULL AND revoked_at IS NULL');
    }
};
