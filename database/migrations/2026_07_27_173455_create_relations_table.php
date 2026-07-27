<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cut_run_id')->index();
            $table->uuid('distributor_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('coordinator_id')->index();
            $table->date('cut_date')->index();
            $table->timestamp('cut_at');
            $table->timestamp('early_payment_starts_at');
            $table->timestamp('early_payment_ends_at');
            $table->timestamp('due_at')->index();
            $table->string('payment_reference')->unique();
            $table->string('financial_status')->index();
            $table->boolean('under_review')->default(false);
            $table->string('payment_behavior')->index();
            $table->decimal('portfolio_total', 19, 4);
            $table->decimal('initial_misvales_due_total', 19, 4);
            $table->decimal('surcharge_total', 19, 4)->default(0);
            $table->decimal('applied_payments_total', 19, 4)->default(0);
            $table->decimal('outstanding_balance', 19, 4);
            $table->decimal('products_capital_basis', 19, 4);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['distributor_id', 'cut_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relations');
    }
};
