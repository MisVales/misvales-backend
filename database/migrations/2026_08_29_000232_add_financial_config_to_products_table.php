<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('loan_commission_percentage', 9, 6)->nullable()->after('status');
            $table->decimal('simple_interest_percentage', 9, 6)->nullable()->after('loan_commission_percentage');
            $table->decimal('insurance_amount', 19, 4)->nullable()->after('simple_interest_percentage');
            $table->unsignedSmallInteger('fortnights_count')->nullable()->after('insurance_amount');
            $table->decimal('late_fee_amount', 19, 4)->nullable()->after('fortnights_count');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'loan_commission_percentage',
                'simple_interest_percentage',
                'insurance_amount',
                'fortnights_count',
                'late_fee_amount',
            ]);
        });
    }
};
