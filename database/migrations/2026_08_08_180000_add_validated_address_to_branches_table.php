<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('address', 500)->nullable()->after('name');
            $table->string('address_validation_id', 255)->nullable()->after('address');
            $table->string('address_place_id', 255)->nullable()->after('address_validation_id');
            $table->decimal('address_latitude', 10, 7)->nullable()->after('address_place_id');
            $table->decimal('address_longitude', 10, 7)->nullable()->after('address_latitude');
            $table->timestampTz('address_validated_at')->nullable()->after('address_longitude');
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS branches_code_sequence START WITH 1');
        $next = ((int) DB::table('branches')
            ->where('code', 'regexp', '^SUC-[0-9]+$')
            ->selectRaw('MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) AS sequence_value')
            ->value('sequence_value')) + 1;
        DB::statement("ALTER SEQUENCE branches_code_sequence RESTART WITH {$next}");
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS branches_code_sequence');

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn([
                'address',
                'address_validation_id',
                'address_place_id',
                'address_latitude',
                'address_longitude',
                'address_validated_at',
            ]);
        });
    }
};
