<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surplus_applications', function (Blueprint $table): void {
            $table->index('payment_id', 'surplus_applications_payment_lookup');
        });
        Schema::table('surplus_applications', function (Blueprint $table): void {
            $table->dropUnique(['payment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('surplus_applications', function (Blueprint $table): void {
            $table->unique('payment_id');
        });
        Schema::table('surplus_applications', function (Blueprint $table): void {
            $table->dropIndex('surplus_applications_payment_lookup');
        });
    }
};
