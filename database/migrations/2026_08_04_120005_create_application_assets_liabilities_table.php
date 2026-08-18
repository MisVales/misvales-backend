<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_assets_liabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('distributor_applications');
            $table->string('entry_type', 32);
            $table->string('name');
            $table->decimal('amount', 19, 4)->nullable();
            $table->decimal('outstanding_balance', 19, 4)->nullable();
            $table->decimal('monthly_payment', 19, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('details_payload')->nullable();
            $table->timestampsTz();

            $table->index('application_id');
        });

        DB::statement("ALTER TABLE application_assets_liabilities ADD CONSTRAINT application_assets_entry_type_check CHECK (entry_type IN ('ASSET', 'LIABILITY', 'ACTIVE_COMMITMENT'))");
        DB::statement('ALTER TABLE application_assets_liabilities ADD CONSTRAINT application_assets_amounts_check CHECK ((amount IS NULL OR amount >= 0) AND (outstanding_balance IS NULL OR outstanding_balance >= 0) AND (monthly_payment IS NULL OR monthly_payment >= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('application_assets_liabilities');
    }
};

