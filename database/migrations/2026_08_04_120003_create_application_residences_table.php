<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        Schema::create('application_residences', function (Blueprint $table) use ($isMysql) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('distributor_applications');
            $table->boolean('is_current')->default(true);
            if ($isMysql) {
                $table->unsignedTinyInteger('current_application_unique')->nullable()->storedAs('IF(is_current = 1, 1, NULL)');
                $table->unique(['application_id', 'current_application_unique'], 'app_residences_current_unique');
            }
            $table->string('street');
            $table->string('exterior_number', 32);
            $table->string('interior_number', 32)->nullable();
            $table->string('neighborhood');
            $table->string('postal_code', 16);
            $table->string('municipality');
            $table->string('city');
            $table->string('state');
            $table->char('country', 2)->default('MX');
            $table->string('housing_tenure', 24);
            $table->string('financing_status', 24)->nullable();
            $table->decimal('width_meters', 10, 2)->nullable();
            $table->decimal('length_meters', 10, 2)->nullable();
            $table->decimal('built_area_square_meters', 12, 2)->nullable();
            $table->json('details_payload')->nullable();
            $table->timestampsTz();

            $table->index('application_id');
        });

        if (! $isMysql) {
            DB::statement('CREATE UNIQUE INDEX app_residences_current_unique ON application_residences (application_id) WHERE is_current = true');
        }

        DB::statement("ALTER TABLE application_residences ADD CONSTRAINT application_residences_tenure_check CHECK (housing_tenure IN ('OWNED', 'RENTED', 'BORROWED', 'OTHER'))");
        DB::statement("ALTER TABLE application_residences ADD CONSTRAINT application_residences_financing_check CHECK (financing_status IS NULL OR financing_status IN ('PAID', 'MORTGAGE', 'LOAN', 'INFONAVIT', 'OTHER', 'NOT_APPLICABLE'))");
        DB::statement('ALTER TABLE application_residences ADD CONSTRAINT application_residences_dimensions_check CHECK ((width_meters IS NULL OR width_meters > 0) AND (length_meters IS NULL OR length_meters > 0) AND (built_area_square_meters IS NULL OR built_area_square_meters > 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('application_residences');
    }
};
