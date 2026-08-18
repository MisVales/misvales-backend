<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_commercial_credits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('distributor_applications');
            $table->string('company_name');
            $table->decimal('credit_limit', 19, 4);
            $table->boolean('is_current')->default(true);
            $table->uuid('proof_reference')->nullable();
            $table->jsonb('details_payload')->nullable();
            $table->timestampsTz();

            $table->index('application_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE application_commercial_credits ADD CONSTRAINT application_commercial_credits_limit_check CHECK (credit_limit >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_commercial_credits');
    }
};

