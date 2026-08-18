<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONFIGURATION_KEYS = [
        'POINTS_DIVISOR_AMOUNT',
        'POINTS_MULTIPLIER',
        'POINT_VALUE_AMOUNT',
        'LATE_POINTS_REDUCTION_RATE',
    ];

    public function up(): void
    {
        Schema::dropIfExists('point_redemption_requests');
        Schema::dropIfExists('point_movements');
        Schema::dropIfExists('point_accounts');
        Schema::dropIfExists('redemption_periods');

        $permissionIds = DB::table('permissions')
            ->where(function ($query): void {
                $query->where('module', 'points')
                    ->orWhere('code', 'like', 'points.%');
            })
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        $definitionIds = DB::table('configuration_definitions')
            ->whereIn('key', self::CONFIGURATION_KEYS)
            ->pluck('id');

        if ($definitionIds->isNotEmpty()) {
            DB::table('configuration_versions')
                ->whereIn('configuration_definition_id', $definitionIds)
                ->delete();
            DB::table('configuration_definitions')->whereIn('id', $definitionIds)->delete();
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The points module removal is destructive and must be reversed with a verified backup or a forward-fix migration.'
        );
    }
};
