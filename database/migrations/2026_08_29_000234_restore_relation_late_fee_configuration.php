<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuration_definitions')->where('key', 'LATE_FEE_AMOUNT')->update([
            'name' => 'Recargo global por relación',
            'description' => 'Importe único aplicado cuando una relación vence con saldo pendiente.',
            'value_type' => 'DECIMAL', 'unit' => 'MXN', 'is_required' => true,
            'is_sensitive' => false, 'status' => 'ACTIVE', 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // No se desactiva ni elimina una configuración financiera referenciable.
    }
};
