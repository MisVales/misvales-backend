<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->decimal('loan_commission_percentage', 8, 6)->nullable()->change();
            $table->decimal('simple_interest_percentage', 8, 6)->nullable()->change();
            $table->decimal('insurance_amount', 12, 4)->nullable()->change();
            $table->integer('fortnights_count')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->decimal('loan_commission_percentage', 8, 6)->nullable(false)->change();
            $table->decimal('simple_interest_percentage', 8, 6)->nullable(false)->change();
            $table->decimal('insurance_amount', 12, 4)->nullable(false)->change();
            $table->integer('fortnights_count')->nullable(false)->change();
        });
    }
};
