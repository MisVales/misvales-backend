<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_risk_alerts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $t->string('type', 32);
            $t->string('status', 24)->default('OPEN');
            $t->unsignedSmallInteger('consecutive_defaults');
            $t->jsonb('relation_ids');
            $t->decimal('overdue_balance', 19, 4);
            $t->timestampsTz();
            $t->unique(['distributor_id', 'type', 'status']);
        });
        Schema::create('distributor_delinquency_decisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $t->foreignUuid('risk_alert_id')->nullable()->constrained('distributor_risk_alerts')->restrictOnDelete();
            $t->string('decision', 24);
            $t->text('reason');
            $t->foreignUuid('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('decided_at');
            $t->timestampsTz();
        });
        Schema::create('delinquency_removal_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $t->foreignUuid('block_id')->constrained('distributor_operational_blocks')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $t->string('status', 24)->default('REQUESTED');
            $t->text('reason');
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_reason')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE distributor_risk_alerts ADD CONSTRAINT risk_alert_status_check CHECK (status IN ('OPEN','REVIEWED','RESOLVED'))");
        DB::statement("ALTER TABLE delinquency_removal_requests ADD CONSTRAINT removal_status_check CHECK (status IN ('REQUESTED','AUTHORIZED','REJECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquency_removal_requests');
        Schema::dropIfExists('distributor_delinquency_decisions');
        Schema::dropIfExists('distributor_risk_alerts');
    }
};

