<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('configuration_definition_id')->constrained('configuration_definitions')->restrictOnDelete();
            $table->integer('version');
            $table->json('value');
            $table->string('status');
            $table->uuid('current_published_definition_id')->nullable()->virtualAs("IF(status = 'PUBLISHED' AND effective_to IS NULL, configuration_definition_id, NULL)")->unique();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['configuration_definition_id', 'version']);
            $table->index(['configuration_definition_id', 'status']);
            $table->index(['configuration_definition_id', 'effective_from', 'effective_to']);
            $table->index(['status', 'effective_from']);
        });

        DB::statement('ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_version CHECK (version > 0);');
        DB::statement('ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_effective_dates CHECK (effective_to IS NULL OR effective_to > effective_from);');
        DB::statement("ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_status CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));");
        DB::statement("ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_published_consistency CHECK (
            (status = 'PUBLISHED' AND published_by IS NOT NULL AND published_at IS NOT NULL)
            OR
            (status <> 'PUBLISHED')
        );");
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_versions');
    }
};
