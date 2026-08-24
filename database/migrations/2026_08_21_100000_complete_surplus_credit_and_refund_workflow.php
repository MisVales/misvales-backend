<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_surpluses', function (Blueprint $table): void {
            $table->foreignUuid('branch_id')->nullable()->after('distributor_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('origin_relation_id')->nullable()->after('branch_id')->constrained('distributor_relations')->restrictOnDelete();
            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['distributor_id', 'status', 'created_at']);
        });

        DB::statement('UPDATE distributor_surpluses SET origin_relation_id = (SELECT relation_id FROM bank_movements WHERE bank_movements.id = distributor_surpluses.bank_movement_id) WHERE origin_relation_id IS NULL');
        DB::statement('UPDATE distributor_surpluses SET branch_id = (SELECT branch_id FROM distributor_relations WHERE distributor_relations.id = distributor_surpluses.origin_relation_id) WHERE branch_id IS NULL');

        if (DB::getDriverName() === 'mysql') {
            Schema::table('distributor_surpluses', function (Blueprint $table): void {
                $table->uuid('branch_id')->nullable(false)->change();
                $table->uuid('origin_relation_id')->nullable(false)->change();
            });
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE distributor_surpluses ALTER COLUMN branch_id SET NOT NULL');
            DB::statement('ALTER TABLE distributor_surpluses ALTER COLUMN origin_relation_id SET NOT NULL');
        }

        Schema::table('surplus_applications', function (Blueprint $table): void {
            $table->foreignUuid('payment_id')->nullable()->after('relation_id')->constrained('relation_payments')->restrictOnDelete();
            $table->decimal('balance_before', 19, 4)->nullable()->after('amount');
            $table->decimal('balance_after', 19, 4)->nullable()->after('balance_before');
            $table->foreignUuid('applied_by')->nullable()->after('balance_after')->constrained('users')->restrictOnDelete();
            $table->string('process', 64)->default('RELATION_GENERATION')->after('applied_by');
            $table->string('idempotency_key', 160)->nullable()->after('process');
            $table->unique('payment_id');
            $table->unique('idempotency_key');
        });

        DB::statement("UPDATE surplus_applications SET idempotency_key = CONCAT('surplus-application:', id) WHERE idempotency_key IS NULL");
        if (DB::getDriverName() === 'mysql') {
            Schema::table('surplus_applications', function (Blueprint $table): void {
                $table->string('idempotency_key', 160)->nullable(false)->change();
            });
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE surplus_applications ALTER COLUMN idempotency_key SET NOT NULL');
        }

        Schema::table('surplus_refund_requests', function (Blueprint $table): void {
            $table->timestampTz('decided_at')->nullable()->after('decision_reason');
            $table->timestampTz('authorized_at')->nullable()->after('decided_at');
            $table->foreignUuid('cancelled_by')->nullable()->after('executed_by')->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->timestampTz('cancelled_at')->nullable()->after('cancellation_reason');
            $table->decimal('execution_amount', 19, 4)->nullable()->after('execution_reference');
            $table->text('execution_observations')->nullable()->after('execution_amount');
            $table->index(['branch_id', 'status', 'created_at']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE distributor_surpluses DROP CONSTRAINT distributor_surpluses_status_check');
            DB::statement("ALTER TABLE distributor_surpluses ADD CONSTRAINT distributor_surpluses_status_check CHECK (status IN ('PENDING_DECISION','CREDIT_BALANCE','PARTIALLY_APPLIED','REFUND_PENDING','REFUNDED','CONSUMED'))");
            DB::statement('ALTER TABLE surplus_refund_requests DROP CONSTRAINT surplus_refunds_status_check');
            DB::statement("ALTER TABLE surplus_refund_requests ADD CONSTRAINT surplus_refunds_status_check CHECK (status IN ('REQUESTED','AUTHORIZED','REJECTED','CANCELLED','EXECUTED'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE surplus_refund_requests DROP CONSTRAINT surplus_refunds_status_check');
            DB::statement("ALTER TABLE surplus_refund_requests ADD CONSTRAINT surplus_refunds_status_check CHECK (status IN ('REQUESTED','AUTHORIZED','REJECTED','EXECUTED'))");
            DB::statement('ALTER TABLE distributor_surpluses DROP CONSTRAINT distributor_surpluses_status_check');
            DB::statement("ALTER TABLE distributor_surpluses ADD CONSTRAINT distributor_surpluses_status_check CHECK (status IN ('PENDING_DECISION','CREDIT_BALANCE','REFUND_PENDING','REFUNDED','CONSUMED'))");
        }

        Schema::table('surplus_refund_requests', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'status', 'created_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['decided_at', 'authorized_at', 'cancellation_reason', 'cancelled_at', 'execution_amount', 'execution_observations']);
        });
        Schema::table('surplus_applications', function (Blueprint $table): void {
            $table->dropUnique(['payment_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('applied_by');
            $table->dropColumn(['balance_before', 'balance_after', 'process', 'idempotency_key']);
        });
        Schema::table('distributor_surpluses', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'status', 'created_at']);
            $table->dropIndex(['distributor_id', 'status', 'created_at']);
            $table->dropConstrainedForeignId('origin_relation_id');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
