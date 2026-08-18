<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_relations', function (Blueprint $t): void {
            $t->timestampTz('settled_at')->nullable();
            $t->string('temporal_classification', 24)->nullable();
        });
        Schema::create('relation_payments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('relation_id')->constrained('distributor_relations')->restrictOnDelete();
            $t->foreignUuid('bank_movement_id')->unique()->constrained('bank_movements')->restrictOnDelete();
            $t->decimal('amount', 19, 4);
            $t->decimal('surcharge_applied', 19, 4)->default(0);
            $t->decimal('interest_applied', 19, 4)->default(0);
            $t->decimal('insurance_applied', 19, 4)->default(0);
            $t->decimal('commission_applied', 19, 4)->default(0);
            $t->decimal('capital_applied', 19, 4)->default(0);
            $t->decimal('line_recovered', 19, 4)->default(0);
            $t->timestampTz('applied_at');
            $t->timestampsTz();
        });
        Schema::create('payment_allocations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('payment_id')->constrained('relation_payments')->restrictOnDelete();
            $t->foreignUuid('relation_item_id')->constrained('distributor_relation_items')->restrictOnDelete();
            $t->string('component', 24);
            $t->decimal('amount', 19, 4);
            $t->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_component_check CHECK (component IN ('SURCHARGE','INTEREST','INSURANCE','LOAN_COMMISSION','CAPITAL'))");
        DB::statement("ALTER TABLE distributor_relations ADD CONSTRAINT distributor_relations_temporal_classification_check CHECK (temporal_classification IS NULL OR temporal_classification IN ('EARLY','ON_TIME','LATE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('relation_payments');
        Schema::table('distributor_relations', fn (Blueprint $t) => $t->dropColumn(['settled_at', 'temporal_classification']));
    }
};

