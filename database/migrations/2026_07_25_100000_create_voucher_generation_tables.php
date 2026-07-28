<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('folio', 64)->unique();
            $table->string('type', 20);
            $table->string('status', 32);
            $table->uuid('distributor_id');
            $table->foreignId('distributor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('product_id');
            $table->uuid('product_version_id');
            $table->uuid('category_id');
            $table->uuid('category_version_id');
            $table->foreignId('credit_line_id')->constrained('credit_lines')->restrictOnDelete();
            $table->foreignId('credit_usage_restriction_id')->nullable()
                ->constrained('credit_usage_restrictions')->restrictOnDelete();
            $table->decimal('capital_amount', 19, 4);
            $table->decimal('credit_available_snapshot', 19, 4);
            $table->decimal('restriction_reference_snapshot', 19, 4)->nullable();
            $table->decimal('restriction_minimum_snapshot', 19, 4)->nullable();
            $table->decimal('restriction_maximum_snapshot', 19, 4)->nullable();
            $table->json('financial_snapshot');
            $table->string('client_name_snapshot', 360);
            $table->string('client_name_normalized', 360);
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('generated_at');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->foreign('product_id')->references('public_id')->on('products')->restrictOnDelete();
            $table->foreign('product_version_id')->references('public_id')->on('product_versions')->restrictOnDelete();
            $table->foreign('category_id')->references('public_id')->on('categories')->restrictOnDelete();
            $table->foreign('category_version_id')->references('public_id')->on('category_versions')->restrictOnDelete();
            $table->index(['distributor_id', 'generated_at']);
            $table->index(['distributor_user_id', 'generated_at']);
            $table->index(['client_id', 'generated_at']);
            $table->index(['branch_id', 'status', 'generated_at']);
            $table->index(['type', 'status']);
            $table->index(['product_id', 'generated_at']);
            $table->index('status');
            $table->index('generated_at');
        });

        Schema::create('voucher_financial_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('voucher_id')->unique()->constrained('vouchers')->restrictOnDelete();
            $table->uuid('product_id');
            $table->uuid('product_version_id');
            $table->unsignedInteger('product_version');
            $table->string('product_name');
            $table->decimal('capital_amount', 19, 4);
            $table->decimal('loan_commission_rate', 9, 6);
            $table->decimal('loan_commission_amount', 19, 4);
            $table->decimal('fortnightly_interest_rate', 9, 6);
            $table->decimal('total_interest_amount', 19, 4);
            $table->decimal('insurance_amount', 19, 4);
            $table->unsignedSmallInteger('fortnights');
            $table->uuid('category_id');
            $table->uuid('category_version_id');
            $table->unsignedInteger('category_version');
            $table->string('category_name');
            $table->decimal('distributor_profit_rate', 9, 6);
            $table->decimal('distributor_profit_amount', 19, 4);
            $table->decimal('misvales_total', 19, 4);
            $table->decimal('base_installment_amount', 19, 4);
            $table->decimal('profit_installment_amount', 19, 4);
            $table->decimal('client_installment_amount', 19, 4);
            $table->decimal('client_total', 19, 4);
            $table->string('calculation_version', 20);
            $table->unsignedSmallInteger('internal_precision');
            $table->string('rounding_rule', 40);
            $table->timestampTz('created_at');
        });

        Schema::create('voucher_installments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('voucher_id')->constrained('vouchers')->restrictOnDelete();
            $table->unsignedSmallInteger('payment_number');
            $table->unsignedSmallInteger('total_payments');
            $table->decimal('capital_amount', 19, 4);
            $table->decimal('loan_commission_amount', 19, 4);
            $table->decimal('interest_amount', 19, 4);
            $table->decimal('insurance_amount', 19, 4);
            $table->decimal('base_payment_amount', 19, 4);
            $table->decimal('distributor_profit_amount', 19, 4);
            $table->decimal('client_total_amount', 19, 4);
            $table->decimal('misvales_due_amount', 19, 4);
            $table->string('relation_status', 30)->default('PENDIENTE');
            $table->uuid('relation_item_id')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['voucher_id', 'payment_number']);
            $table->index(['relation_status', 'relation_item_id']);
        });

        $this->addPostgreSqlIntegrity();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS protect_voucher_generation_evidence() CASCADE;
                 DROP FUNCTION IF EXISTS protect_voucher_generation_core() CASCADE;
                 DROP FUNCTION IF EXISTS protect_voucher_installment_financials() CASCADE;
                 DROP FUNCTION IF EXISTS enforce_voucher_status_transition() CASCADE;',
            );
        }
        Schema::dropIfExists('voucher_installments');
        Schema::dropIfExists('voucher_financial_snapshots');
        Schema::dropIfExists('vouchers');
    }

    private function addPostgreSqlIntegrity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE vouchers ADD CONSTRAINT vouchers_type_check
                CHECK (type IN ('PREVALE', 'VALE_DIGITAL'));
            ALTER TABLE vouchers ADD CONSTRAINT vouchers_status_check
                CHECK (status IN ('GENERADO', 'VALIDACION_CAJA', 'CORRECCION_PENDIENTE', 'LIBERADO', 'FERIADO', 'RECHAZADO', 'CANCELADO'));
            ALTER TABLE vouchers ADD CONSTRAINT vouchers_financial_values_check CHECK (
                capital_amount > 0 AND credit_available_snapshot >= 0
                AND (restriction_reference_snapshot IS NULL OR restriction_reference_snapshot >= 0)
                AND (restriction_minimum_snapshot IS NULL OR restriction_minimum_snapshot >= 0)
                AND (restriction_maximum_snapshot IS NULL OR restriction_maximum_snapshot >= restriction_minimum_snapshot)
            );
            ALTER TABLE voucher_financial_snapshots ADD CONSTRAINT voucher_snapshot_values_check CHECK (
                capital_amount > 0
                AND loan_commission_rate BETWEEN 0 AND 1 AND loan_commission_amount >= 0
                AND fortnightly_interest_rate BETWEEN 0 AND 1 AND total_interest_amount >= 0
                AND insurance_amount >= 0 AND fortnights > 0
                AND distributor_profit_rate BETWEEN 0 AND 1 AND distributor_profit_amount >= 0
                AND misvales_total >= 0 AND base_installment_amount >= 0
                AND profit_installment_amount >= 0 AND client_installment_amount >= 0
                AND client_total >= 0 AND internal_precision = 4 AND rounding_rule = 'HALF_UP'
            );
            ALTER TABLE voucher_installments ADD CONSTRAINT voucher_installments_number_check
                CHECK (payment_number >= 1 AND payment_number <= total_payments);
            ALTER TABLE voucher_installments ADD CONSTRAINT voucher_installments_amounts_check CHECK (
                capital_amount >= 0 AND loan_commission_amount >= 0 AND interest_amount >= 0
                AND insurance_amount >= 0 AND base_payment_amount >= 0
                AND distributor_profit_amount >= 0 AND client_total_amount >= 0
                AND misvales_due_amount >= 0
            );

            CREATE UNIQUE INDEX vouchers_one_prevale_per_client
                ON vouchers (client_id) WHERE type = 'PREVALE';

            CREATE OR REPLACE FUNCTION protect_voucher_generation_evidence() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Voucher generation evidence is immutable';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER voucher_snapshot_immutable
                BEFORE UPDATE OR DELETE ON voucher_financial_snapshots
                FOR EACH ROW EXECUTE FUNCTION protect_voucher_generation_evidence();
            CREATE TRIGGER voucher_installments_no_delete
                BEFORE DELETE ON voucher_installments
                FOR EACH ROW EXECUTE FUNCTION protect_voucher_generation_evidence();
            CREATE TRIGGER vouchers_no_delete
                BEFORE DELETE ON vouchers
                FOR EACH ROW EXECUTE FUNCTION protect_voucher_generation_evidence();

            CREATE OR REPLACE FUNCTION protect_voucher_generation_core() RETURNS trigger AS $$
            BEGIN
                IF (NEW.id, NEW.folio, NEW.type, NEW.distributor_id, NEW.distributor_user_id,
                    NEW.client_id, NEW.branch_id, NEW.product_id, NEW.product_version_id,
                    NEW.category_id, NEW.category_version_id, NEW.credit_line_id,
                    NEW.credit_usage_restriction_id, NEW.capital_amount, NEW.credit_available_snapshot,
                    NEW.restriction_reference_snapshot, NEW.restriction_minimum_snapshot,
                    NEW.restriction_maximum_snapshot, NEW.financial_snapshot::jsonb,
                    NEW.client_name_snapshot, NEW.client_name_normalized, NEW.generated_by,
                    NEW.generated_at, NEW.created_at)
                    IS DISTINCT FROM
                   (OLD.id, OLD.folio, OLD.type, OLD.distributor_id, OLD.distributor_user_id,
                    OLD.client_id, OLD.branch_id, OLD.product_id, OLD.product_version_id,
                    OLD.category_id, OLD.category_version_id, OLD.credit_line_id,
                    OLD.credit_usage_restriction_id, OLD.capital_amount, OLD.credit_available_snapshot,
                    OLD.restriction_reference_snapshot, OLD.restriction_minimum_snapshot,
                    OLD.restriction_maximum_snapshot, OLD.financial_snapshot::jsonb,
                    OLD.client_name_snapshot, OLD.client_name_normalized, OLD.generated_by,
                    OLD.generated_at, OLD.created_at) THEN
                    RAISE EXCEPTION 'Voucher identity and financial context are immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER voucher_generation_core_immutable
                BEFORE UPDATE ON vouchers
                FOR EACH ROW EXECUTE FUNCTION protect_voucher_generation_core();

            CREATE OR REPLACE FUNCTION protect_voucher_installment_financials() RETURNS trigger AS $$
            BEGIN
                IF (NEW.id, NEW.voucher_id, NEW.payment_number, NEW.total_payments,
                    NEW.capital_amount, NEW.loan_commission_amount, NEW.interest_amount,
                    NEW.insurance_amount, NEW.base_payment_amount, NEW.distributor_profit_amount,
                    NEW.client_total_amount, NEW.misvales_due_amount, NEW.created_at)
                    IS DISTINCT FROM
                   (OLD.id, OLD.voucher_id, OLD.payment_number, OLD.total_payments,
                    OLD.capital_amount, OLD.loan_commission_amount, OLD.interest_amount,
                    OLD.insurance_amount, OLD.base_payment_amount, OLD.distributor_profit_amount,
                    OLD.client_total_amount, OLD.misvales_due_amount, OLD.created_at) THEN
                    RAISE EXCEPTION 'Voucher installment financials are immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER voucher_installment_financials_immutable
                BEFORE UPDATE ON voucher_installments
                FOR EACH ROW EXECUTE FUNCTION protect_voucher_installment_financials();

            CREATE OR REPLACE FUNCTION enforce_voucher_status_transition() RETURNS trigger AS $$
            BEGIN
                IF NEW.status IS NOT DISTINCT FROM OLD.status THEN RETURN NEW; END IF;
                IF NEW.lock_version <> OLD.lock_version + 1 THEN
                    RAISE EXCEPTION 'Voucher state changes require a new lock version';
                END IF;
                IF NOT (
                    (OLD.status = 'GENERADO' AND NEW.status IN ('VALIDACION_CAJA', 'CANCELADO'))
                    OR (OLD.status = 'VALIDACION_CAJA' AND NEW.status IN ('CORRECCION_PENDIENTE', 'LIBERADO', 'RECHAZADO'))
                    OR (OLD.status = 'CORRECCION_PENDIENTE' AND NEW.status = 'VALIDACION_CAJA')
                    OR (OLD.status = 'LIBERADO' AND NEW.status = 'FERIADO')
                ) THEN
                    RAISE EXCEPTION 'Invalid voucher transition: % to %', OLD.status, NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER voucher_status_transition
                BEFORE UPDATE OF status ON vouchers
                FOR EACH ROW EXECUTE FUNCTION enforce_voucher_status_transition();
        SQL);
    }
};
