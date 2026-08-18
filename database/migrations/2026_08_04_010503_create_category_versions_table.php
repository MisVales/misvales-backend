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

        Schema::create('category_versions', function (Blueprint $table) use ($isMysql) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->integer('version');
            $table->decimal('profit_rate', 9, 6);
            $table->string('status');
            if ($isMysql) {
                $table->unsignedTinyInteger('current_published_category_id')->nullable()->storedAs("IF(status = 'PUBLISHED' AND effective_to IS NULL, 1, NULL)");
                $table->unique(['category_id', 'current_published_category_id'], 'catv_current_published_unique');
            }
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['category_id', 'version']);
            $table->index(['category_id', 'status']);
            $table->index(['category_id', 'effective_from', 'effective_to']);
        });

        if (! $isMysql) {
            DB::statement("CREATE UNIQUE INDEX catv_current_published_unique ON category_versions (category_id) WHERE status = 'PUBLISHED' AND effective_to IS NULL");
        }

        DB::statement('ALTER TABLE category_versions ADD CONSTRAINT chk_catv_version CHECK (version > 0);');
        DB::statement('ALTER TABLE category_versions ADD CONSTRAINT chk_catv_profit_rate CHECK (profit_rate >= 0 AND profit_rate <= 1);');
        DB::statement('ALTER TABLE category_versions ADD CONSTRAINT chk_catv_effective_dates CHECK (effective_to IS NULL OR effective_to > effective_from);');
        DB::statement("ALTER TABLE category_versions ADD CONSTRAINT chk_catv_status CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));");
    }

    public function down(): void
    {
        Schema::dropIfExists('category_versions');
    }
};
