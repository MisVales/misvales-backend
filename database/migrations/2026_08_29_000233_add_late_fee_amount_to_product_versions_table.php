<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_versions', 'late_fee_amount')) {
            return;
        }

        Schema::table('product_versions', function (Blueprint $table): void {
            $table->decimal('late_fee_amount', 19, 4)->nullable()->after('fortnights_count');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_versions', 'late_fee_amount')) {
            return;
        }

        Schema::table('product_versions', function (Blueprint $table): void {
            $table->dropColumn('late_fee_amount');
        });
    }
};
