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

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS branches_code_sequence START WITH 1');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER SEQUENCE branches_code_sequence OWNED BY branches.code');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(<<<'SQL'
                SELECT setval(
                    'branches_code_sequence',
                    COALESCE((
                        SELECT MAX(CAST(substring(code FROM 5) AS BIGINT))
                        FROM branches
                        WHERE code ~ '^SUC-[0-9]+$'
                    ), 0) + 1,
                    false
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP SEQUENCE IF EXISTS branches_code_sequence');
        }

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
