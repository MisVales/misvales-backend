<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_file_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_file_id');
            $table->string('owner_type');
            $table->uuid('owner_id');
            $table->string('purpose');
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->foreign('media_file_id')->references('id')->on('media_files')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->index(['owner_type', 'owner_id']);
            $table->unique(['media_file_id', 'owner_type', 'owner_id', 'purpose'], 'media_file_bindings_owner_unique');
        });

        // Migrate existing verification_visit_id from media_files to media_file_bindings
        if (Schema::hasColumn('media_files', 'verification_visit_id')) {
            DB::statement("
                INSERT INTO media_file_bindings (id, media_file_id, owner_type, owner_id, purpose, created_by, created_at, updated_at)
                SELECT
                    UUID(),
                    id,
                    'verification_visit',
                    verification_visit_id,
                    'EVIDENCE',
                    uploaded_by,
                    created_at,
                    updated_at
                FROM media_files
                WHERE verification_visit_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_bindings');
    }
};
