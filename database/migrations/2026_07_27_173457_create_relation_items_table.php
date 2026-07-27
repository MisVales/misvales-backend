<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('relation_id')->index();
            $table->uuid('voucher_installment_id')->unique();
            $table->uuid('voucher_id')->index();
            $table->uuid('client_id')->index();
            $table->unsignedInteger('payment_number');
            $table->unsignedInteger('total_payments');
            $table->json('product_snapshot');
            $table->json('category_snapshot');
            $table->decimal('capital_amount', 19, 4);
            $table->decimal('loan_commission_amount', 19, 4);
            $table->decimal('interest_amount', 19, 4);
            $table->decimal('insurance_amount', 19, 4);
            $table->decimal('distributor_profit_amount', 19, 4);
            $table->decimal('base_payment_amount', 19, 4);
            $table->decimal('client_charge_amount', 19, 4);
            $table->decimal('misvales_due_amount', 19, 4);
            $table->decimal('surcharge_amount', 19, 4)->default(0);
            $table->decimal('applied_amount', 19, 4)->default(0);
            $table->decimal('outstanding_amount', 19, 4);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['relation_id', 'voucher_id', 'payment_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relation_items');
    }
};
