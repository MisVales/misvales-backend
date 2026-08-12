<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Los usuarios usan UUID. Los tokens existentes con identificadores
        // enteros no pueden pertenecer válidamente a esos usuarios.
        DB::table('personal_access_tokens')->delete();
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->char('tokenable_id', 36)->change();
        });
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')->delete();
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('tokenable_id')->change();
        });
    }
};
