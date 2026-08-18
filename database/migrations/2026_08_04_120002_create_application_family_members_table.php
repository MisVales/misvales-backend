<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_family_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('distributor_applications');
            $table->string('relationship', 24);
            $table->string('first_name');
            $table->string('first_last_name');
            $table->string('second_last_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('declared_age')->nullable();
            $table->string('school_name')->nullable();
            $table->boolean('is_family_reference')->default(false);
            $table->jsonb('details_payload')->nullable();
            $table->timestampsTz();

            $table->index('application_id');
        });

        DB::statement("ALTER TABLE application_family_members ADD CONSTRAINT application_family_relationship_check CHECK (relationship IN ('SPOUSE', 'PARTNER', 'CHILD', 'FATHER', 'MOTHER', 'SIBLING', 'OTHER'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE application_family_members ADD CONSTRAINT application_family_age_check CHECK (declared_age IS NULL OR declared_age <= 130)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_family_members');
    }
};

