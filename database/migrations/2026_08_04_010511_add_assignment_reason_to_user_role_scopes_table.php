<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_role_scopes', function (Blueprint $table): void {
            $table->string('assignment_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_role_scopes', function (Blueprint $table): void {
            $table->dropColumn('assignment_reason');
        });
    }
};
