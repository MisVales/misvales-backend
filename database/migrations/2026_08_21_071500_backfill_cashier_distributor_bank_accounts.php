<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $cuentas = DB::table('client_bank_accounts as cuenta')
            ->join('client_distributor_assignments as asignacion', function ($join): void {
                $join->on('asignacion.client_id', '=', 'cuenta.client_id')
                    ->whereNull('asignacion.ends_at');
            })
            ->join('distributors as distribuidora', 'distribuidora.id', '=', 'asignacion.distributor_id')
            ->join('users as usuario', 'usuario.id', '=', 'distribuidora.user_id')
            ->where('cuenta.change_reason', 'Capturado en caja durante la liberación del primer vale.')
            ->where('cuenta.is_current', true)
            ->whereNull('cuenta.ends_at')
            ->orderBy('cuenta.created_at')
            ->select([
                'cuenta.bank_name',
                'cuenta.clabe_ciphertext',
                'cuenta.clabe_hmac',
                'cuenta.created_at',
                'cuenta.created_by',
                'distribuidora.id as distributor_id',
                'usuario.name as account_holder_name',
            ])
            ->get();

        foreach ($cuentas as $cuenta) {
            $existe = DB::table('distributor_bank_accounts')
                ->where('distributor_id', $cuenta->distributor_id)
                ->where('is_current', true)
                ->whereNull('ends_at')
                ->exists();

            if ($existe) {
                continue;
            }

            DB::table('distributor_bank_accounts')->insert([
                'id' => (string) Str::uuid(),
                'distributor_id' => $cuenta->distributor_id,
                'bank_name' => $cuenta->bank_name,
                'account_holder_name' => $cuenta->account_holder_name,
                'clabe_ciphertext' => $cuenta->clabe_ciphertext,
                'clabe_hmac' => $cuenta->clabe_hmac,
                'is_current' => true,
                'starts_at' => $cuenta->created_at,
                'created_by' => $cuenta->created_by,
                'change_reason' => 'Migrada desde la captura inicial de caja.',
                'lock_version' => 1,
                'created_at' => $cuenta->created_at,
                'updated_at' => $cuenta->created_at,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('distributor_bank_accounts')
            ->where('change_reason', 'Migrada desde la captura inicial de caja.')
            ->delete();
    }
};
