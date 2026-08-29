<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relation_late_fees', function (Blueprint $table): void {
            $table->timestampTz('voided_at')->nullable()->after('configuration_snapshot');
            $table->string('void_reason', 255)->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('relation_late_fees', function (Blueprint $table): void {
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
