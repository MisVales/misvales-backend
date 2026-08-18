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
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE uuid USING tokenable_id::text::uuid');
        }
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')->delete();
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE bigint USING 0');
        }
    }
};
