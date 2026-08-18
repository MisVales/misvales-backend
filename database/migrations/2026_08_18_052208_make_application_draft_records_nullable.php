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
        Schema::table('application_family_members', function (Blueprint $table) {
            $table->string('relationship', 24)->nullable()->change();
            $table->string('first_name')->nullable()->change();
            $table->string('first_last_name')->nullable()->change();
        });

        Schema::table('application_residences', function (Blueprint $table) {
            $table->string('street')->nullable()->change();
            $table->string('exterior_number', 32)->nullable()->change();
            $table->string('neighborhood')->nullable()->change();
            $table->string('postal_code', 16)->nullable()->change();
            $table->string('municipality')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('state')->nullable()->change();
            $table->string('housing_tenure', 24)->nullable()->change();
        });

        Schema::table('application_vehicles', function (Blueprint $table) {
            $table->string('vehicle_type', 64)->nullable()->change();
        });

        Schema::table('application_assets_liabilities', function (Blueprint $table) {
            $table->string('entry_type', 32)->nullable()->change();
            $table->string('name')->nullable()->change();
        });

        Schema::table('application_employments', function (Blueprint $table) {
            $table->string('employer_name')->nullable()->change();
        });

        Schema::table('application_commercial_credits', function (Blueprint $table) {
            $table->string('company_name')->nullable()->change();
            $table->decimal('credit_limit', 19, 4)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A deployed draft may contain NULLs by design. Restoring NOT NULL
        // constraints would either lose that draft or make a rollback fail.
        throw new \LogicException('La migración de borradores parciales es irreversible; aplica una corrección hacia adelante.');
    }
};
