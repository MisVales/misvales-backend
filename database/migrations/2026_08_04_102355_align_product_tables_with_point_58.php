<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropColumn('description');
        });

        // Drop checks that depend on columns to be renamed
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_amount');
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_amount_mod');
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_commission');
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_interest');
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_fortnights');

        Schema::table('product_versions', function (Blueprint $table) {
            $table->string('name')->after('version');
            $table->text('description')->nullable()->after('name');
            $table->renameColumn('amount', 'nominal_amount');
            $table->renameColumn('loan_commission_rate', 'loan_commission_percentage');
            $table->renameColumn('interest_rate_per_fortnight', 'simple_interest_percentage');
            $table->renameColumn('fortnights', 'fortnights_count');
            $table->integer('lock_version')->default(0)->after('status');
        });

        // Recreate checks
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_nominal_amount CHECK (nominal_amount > 0)");
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_nominal_amount_mod CHECK (mod(nominal_amount, 100.0000) = 0)");
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_loan_commission CHECK (loan_commission_percentage >= 0 AND loan_commission_percentage <= 1)");
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_simple_interest CHECK (simple_interest_percentage >= 0 AND simple_interest_percentage <= 1)");
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_fortnights_count CHECK (fortnights_count > 0)");
    }

    public function down(): void
    {
        // Add them back for rollback
        Schema::table('products', function (Blueprint $table) {
            $table->string('name')->default('')->after('code');
            $table->text('description')->nullable()->after('name');
        });

        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_nominal_amount');
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_nominal_amount_mod');
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_loan_commission');
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_simple_interest');
        DB::statement('ALTER TABLE product_versions DROP CONSTRAINT chk_pv_fortnights_count');

        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropColumn('description');
            $table->dropColumn('lock_version');
            $table->renameColumn('nominal_amount', 'amount');
            $table->renameColumn('loan_commission_percentage', 'loan_commission_rate');
            $table->renameColumn('simple_interest_percentage', 'interest_rate_per_fortnight');
            $table->renameColumn('fortnights_count', 'fortnights');
        });

        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_amount CHECK (amount > 0)");
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_amount_mod CHECK (mod(amount, 100.0000) = 0)");
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_commission CHECK (loan_commission_rate >= 0 AND loan_commission_rate <= 1)");
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_interest CHECK (interest_rate_per_fortnight >= 0 AND interest_rate_per_fortnight <= 1)");
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_fortnights CHECK (fortnights > 0)");
    }
};
