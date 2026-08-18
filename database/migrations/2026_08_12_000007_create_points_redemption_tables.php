<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_accounts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('distributor_id')->unique()->constrained('distributors')->restrictOnDelete();
            $t->bigInteger('balance')->default(0);
            $t->bigInteger('reserved')->default(0);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('point_movements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('point_account_id')->constrained('point_accounts')->restrictOnDelete();
            $t->foreignUuid('relation_id')->nullable()->constrained('distributor_relations')->restrictOnDelete();
            $t->string('type', 24);
            $t->bigInteger('balance_before');
            $t->bigInteger('generated')->default(0);
            $t->bigInteger('discounted')->default(0);
            $t->bigInteger('redeemed')->default(0);
            $t->bigInteger('balance_after');
            $t->text('reason');
            $t->jsonb('rule_snapshot');
            $t->string('rule_version');
            $t->foreignUuid('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('occurred_at');
            $t->timestampsTz();
            $t->unique(['relation_id', 'type']);
        });
        Schema::create('point_redemption_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('point_account_id')->constrained('point_accounts')->restrictOnDelete();
            $t->foreignUuid('redemption_period_id')->constrained('redemption_periods')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $t->bigInteger('points');
            $t->decimal('point_value_snapshot', 19, 4);
            $t->decimal('monetary_value', 19, 4);
            $t->string('status', 24)->default('REQUESTED');
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_reason')->nullable();
            $t->foreignUuid('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('delivery_reference')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampsTz();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE point_accounts ADD CONSTRAINT point_accounts_balance_check CHECK (balance >= 0 AND reserved >= 0 AND reserved <= balance)');
        }
        DB::statement("ALTER TABLE point_movements ADD CONSTRAINT point_movements_type_check CHECK (type IN ('EARLY_GENERATION','LATE_DISCOUNT','REDEMPTION'))");
        DB::statement("ALTER TABLE point_redemption_requests ADD CONSTRAINT point_redemptions_status_check CHECK (status IN ('REQUESTED','AUTHORIZED','REJECTED','DELIVERED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('point_redemption_requests');
        Schema::dropIfExists('point_movements');
        Schema::dropIfExists('point_accounts');
    }
};

