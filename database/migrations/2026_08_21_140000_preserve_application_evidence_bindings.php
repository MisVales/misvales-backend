<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $owners = [
            'application_vehicle' => 'application_vehicles',
            'application_asset_liability' => 'application_assets_liabilities',
            'application_commercial_credit' => 'application_commercial_credits',
        ];

        foreach ($owners as $ownerType => $table) {
            DB::table('media_file_bindings as binding')
                ->join("{$table} as record", 'record.id', '=', 'binding.owner_id')
                ->where('binding.owner_type', $ownerType)
                ->select([
                    'binding.media_file_id', 'binding.purpose', 'binding.created_by',
                    'binding.created_at', 'binding.updated_at', 'record.application_id',
                ])
                ->orderBy('binding.id')
                ->chunk(200, function ($bindings): void {
                    foreach ($bindings as $binding) {
                        DB::table('media_file_bindings')->insertOrIgnore([
                            'id' => (string) Str::uuid(),
                            'media_file_id' => $binding->media_file_id,
                            'owner_type' => 'distributor_application',
                            'owner_id' => $binding->application_id,
                            'purpose' => $binding->purpose,
                            'created_by' => $binding->created_by,
                            'created_at' => $binding->created_at,
                            'updated_at' => $binding->updated_at,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Los vínculos históricos se conservan para no volver a ocultar evidencias del expediente.
    }
};
