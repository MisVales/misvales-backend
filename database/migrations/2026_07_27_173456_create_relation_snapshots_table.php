<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relation_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('relation_id')->unique();
            $table->string('distributor_number');
            $table->string('distributor_name');
            $table->text('distributor_address_snapshot');
            $table->string('branch_snapshot');
            $table->string('coordinator_snapshot');
            $table->decimal('total_credit_line', 19, 4);
            $table->decimal('used_balance', 19, 4);
            $table->decimal('available_balance', 19, 4);
            $table->decimal('points_balance', 19, 4);
            $table->string('timezone');
            $table->json('configuration_versions');
            $table->string('payment_beneficiary');
            $table->string('payment_bank');
            $table->string('payment_agreement');
            $table->string('payment_clabe');
            $table->string('engine_version');
            $table->integer('precision')->default(4);
            $table->string('rounding_rule')->default('HALF_UP');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relation_snapshots');
    }
};
