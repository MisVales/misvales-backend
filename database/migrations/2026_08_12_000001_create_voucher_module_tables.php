<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS voucher_folio_seq START WITH 1 INCREMENT BY 1 NO CYCLE');
        }

        Schema::create('distributor_operational_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->string('type', 48);
            $table->string('status', 16)->default('ACTIVE');
            $table->string('source_type', 64);
            $table->uuid('source_id');
            $table->text('reason');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['distributor_id', 'type', 'status']);
            $table->unique(['type', 'source_type', 'source_id']);
        });
        DB::statement("ALTER TABLE distributor_operational_blocks ADD CONSTRAINT distributor_operational_blocks_type_check CHECK (type IN ('DELINQUENCY'))");
        DB::statement("ALTER TABLE distributor_operational_blocks ADD CONSTRAINT distributor_operational_blocks_status_check CHECK (status IN ('ACTIVE', 'RELEASED'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE distributor_operational_blocks ADD CONSTRAINT distributor_operational_blocks_dates_check CHECK (ends_at IS NULL OR ends_at > starts_at)');
        }

        Schema::create('vouchers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('folio', 32)->unique();
            $table->string('type', 24);
            $table->string('status', 32)->default('GENERATED');
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('credit_line_id')->constrained('credit_lines')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('product_version_id')->constrained('product_versions')->restrictOnDelete();
            $table->foreignUuid('category_version_id')->constrained('category_versions')->restrictOnDelete();
            $table->foreignUuid('credit_restriction_id')->nullable()->constrained('credit_usage_restrictions')->restrictOnDelete();
            $table->decimal('capital', 19, 4);
            $table->decimal('loan_commission_percentage', 9, 6);
            $table->decimal('loan_commission_amount', 19, 4);
            $table->decimal('simple_interest_percentage', 9, 6);
            $table->smallInteger('fortnights_count');
            $table->decimal('insurance_amount', 19, 4);
            $table->decimal('interest_total', 19, 4);
            $table->decimal('misvales_total', 19, 4);
            $table->decimal('misvales_payment_per_fortnight', 19, 4);
            $table->decimal('distributor_profit_percentage', 9, 6);
            $table->decimal('distributor_profit_total', 19, 4);
            $table->decimal('distributor_profit_per_fortnight', 19, 4);
            $table->decimal('client_payment_per_fortnight', 19, 4);
            $table->decimal('client_total', 19, 4);
            $table->jsonb('financial_snapshot');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->index(['distributor_id', 'generated_at']);
            $table->index(['client_id', 'generated_at']);
            $table->index(['branch_id', 'status']);
        });

        DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_type_check CHECK (type IN ('PREVALE', 'VALE_DIGITAL'))");
        DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_status_check CHECK (status IN ('GENERATED', 'CASH_VALIDATION', 'CORRECTION_PENDING', 'RELEASED', 'CASHED', 'REJECTED', 'CANCELLED'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_money_check CHECK (capital > 0 AND loan_commission_amount >= 0 AND insurance_amount >= 0 AND interest_total >= 0 AND misvales_total > 0 AND distributor_profit_total >= 0 AND client_total > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_rates_check CHECK (loan_commission_percentage BETWEEN 0 AND 1 AND simple_interest_percentage BETWEEN 0 AND 1 AND distributor_profit_percentage BETWEEN 0 AND 1)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_fortnights_check CHECK (fortnights_count > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_lock_version_check CHECK (lock_version >= 1)');
        }

        Schema::create('voucher_installments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('voucher_id')->constrained('vouchers')->restrictOnDelete();
            $table->smallInteger('number');
            $table->decimal('capital', 19, 4);
            $table->decimal('loan_commission', 19, 4);
            $table->decimal('interest', 19, 4);
            $table->decimal('insurance', 19, 4);
            $table->decimal('distributor_profit', 19, 4);
            $table->decimal('misvales_payment', 19, 4);
            $table->decimal('client_payment', 19, 4);
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['voucher_id', 'number']);
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE voucher_installments ADD CONSTRAINT voucher_installments_number_check CHECK (number > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE voucher_installments ADD CONSTRAINT voucher_installments_money_check CHECK (capital >= 0 AND loan_commission >= 0 AND interest >= 0 AND insurance >= 0 AND distributor_profit >= 0 AND misvales_payment >= 0 AND client_payment >= 0)');
        }

        Schema::table('credit_usage_restrictions', function (Blueprint $table): void {
            $table->foreign('reserved_voucher_id')->references('id')->on('vouchers')->restrictOnDelete();
        });
        Schema::table('client_portfolio_entries', function (Blueprint $table): void {
            $table->foreign('related_voucher_id')->references('id')->on('vouchers')->restrictOnDelete();
        });
        if (DB::getDriverName() !== 'sqlite') {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement("CREATE OR REPLACE FUNCTION prevent_voucher_deletion() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Los vales no se eliminan fÃ­sicamente.'; END; $$ LANGUAGE plpgsql");
                DB::statement('CREATE TRIGGER trg_prevent_voucher_deletion BEFORE DELETE ON vouchers FOR EACH ROW EXECUTE FUNCTION prevent_voucher_deletion()');
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS trg_prevent_voucher_deletion ON vouchers');
            DB::statement('DROP FUNCTION IF EXISTS prevent_voucher_deletion()');
        }
        Schema::table('client_portfolio_entries', function (Blueprint $table): void {
            $table->dropForeign(['related_voucher_id']);
        });
        Schema::table('credit_usage_restrictions', function (Blueprint $table): void {
            $table->dropForeign(['reserved_voucher_id']);
        });
        Schema::dropIfExists('voucher_installments');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('distributor_operational_blocks');
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP SEQUENCE IF EXISTS voucher_folio_seq');
        }
    }
};
