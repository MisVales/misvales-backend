<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('simulated_bank_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('relation_id')->constrained('distributor_relations')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->text('concept');
            $table->string('payment_reference', 64);
            $table->decimal('amount', 19, 4);
            $table->string('bank_folio', 100)->unique();
            $table->timestampTz('paid_at');
            $table->string('payment_type', 32);
            $table->timestampsTz();
            $table->index(['branch_id', 'paid_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulated_bank_transfers');
    }
};
