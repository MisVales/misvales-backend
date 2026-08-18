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

        Schema::create('configuration_versions', function (Blueprint $table) use ($isMysql) {
            $table->uuid('id')->primary();
            $table->foreignUuid('configuration_definition_id')->constrained('configuration_definitions')->restrictOnDelete();
            $table->integer('version');
            $table->json('value');
            $table->string('status');
            if ($isMysql) {
                $table->unsignedTinyInteger('current_published_definition_id')->nullable()->storedAs("IF(status = 'PUBLISHED' AND effective_to IS NULL, 1, NULL)");
                $table->unique(['configuration_definition_id', 'current_published_definition_id'], 'cv_current_published_unique');
            } else {
                $table->uuid('current_published_definition_id')->nullable()->virtualAs("IF(status = 'PUBLISHED' AND effective_to IS NULL, configuration_definition_id, NULL)")->unique();
            }
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['configuration_definition_id', 'version'], 'cv_definition_version_unique');
            $table->index(['configuration_definition_id', 'status'], 'cv_definition_status_index');
            $table->index(['configuration_definition_id', 'effective_from', 'effective_to'], 'cv_definition_effective_index');
            $table->index(['status', 'effective_from']);
        });

        DB::statement('ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_version CHECK (version > 0);');
        DB::statement('ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_effective_dates CHECK (effective_to IS NULL OR effective_to > effective_from);');
        DB::statement("ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_status CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));");
        if ($isMysql) {
            DB::statement("ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_published_consistency CHECK (
                (status = 'PUBLISHED' AND published_at IS NOT NULL)
                OR
                (status <> 'PUBLISHED')
            );");
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_cv_published_consistency_insert
                BEFORE INSERT ON configuration_versions
                FOR EACH ROW
                BEGIN
                    IF NEW.status = 'PUBLISHED' AND NEW.published_by IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una version publicada requiere publicador.';
                    END IF;
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_cv_published_consistency_update
                BEFORE UPDATE ON configuration_versions
                FOR EACH ROW
                BEGIN
                    IF NEW.status = 'PUBLISHED' AND NEW.published_by IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una version publicada requiere publicador.';
                    END IF;
                END
            SQL);
        } else {
            DB::statement("ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_published_consistency CHECK (
                (status = 'PUBLISHED' AND published_by IS NOT NULL AND published_at IS NOT NULL)
                OR
                (status <> 'PUBLISHED')
            );");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_versions');
    }
};
