<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Los usuarios usan UUID. Los tokens existentes con identificadores
        // enteros no pueden pertenecer válidamente a esos usuarios.
        DB::table('personal_access_tokens')->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE uuid USING tokenable_id::text::uuid');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE personal_access_tokens MODIFY tokenable_id CHAR(36) NOT NULL');
        }
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE bigint USING 0');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE personal_access_tokens MODIFY tokenable_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
