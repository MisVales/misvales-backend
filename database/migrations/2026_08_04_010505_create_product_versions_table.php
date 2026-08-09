<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('version');
            $table->decimal('amount', 19, 4);
            $table->decimal('loan_commission_rate', 9, 6);
            $table->decimal('interest_rate_per_fortnight', 9, 6);
            $table->decimal('insurance_amount', 19, 4);
            $table->smallInteger('fortnights');
            $table->string('status');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['product_id', 'version']);
            $table->index(['product_id', 'status']);
            $table->index(['product_id', 'effective_from', 'effective_to']);
            $table->index(['status', 'effective_from']);
        });

        DB::statement("
            CREATE UNIQUE INDEX product_versions_open_published_unique
            ON product_versions (product_id)
            WHERE status = 'PUBLISHED' AND effective_to IS NULL;
        ");

        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT chk_pv_version CHECK (version > 0);');
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT chk_pv_amount CHECK (amount > 0);');
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT chk_pv_amount_mod CHECK (mod(amount, 100.0000) = 0);');
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT chk_pv_commission CHECK (loan_commission_rate >= 0 AND loan_commission_rate <= 1);');
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT chk_pv_interest CHECK (interest_rate_per_fortnight >= 0 AND interest_rate_per_fortnight <= 1);');
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT chk_pv_insurance CHECK (insurance_amount >= 0);');
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT chk_pv_fortnights CHECK (fortnights > 0);');
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT chk_pv_effective_dates CHECK (effective_to IS NULL OR effective_to > effective_from);');
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT chk_pv_status CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_versions');
    }
};
