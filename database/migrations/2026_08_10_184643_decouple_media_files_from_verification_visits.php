<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('media_files', 'verification_visit_id')) {
            return;
        }

        Schema::table('media_files', function (Blueprint $table) {
            // Drop unique index first
            $table->dropUnique(['verification_visit_id', 'sha256']);

            // Drop foreign key
            $table->dropForeign(['verification_visit_id']);

            // Drop column
            $table->dropColumn('verification_visit_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->uuid('verification_visit_id')->nullable(); // nullable for safe rollback
            $table->foreign('verification_visit_id')->references('id')->on('verification_visits')->restrictOnDelete();
            $table->unique(['verification_visit_id', 'sha256']);
        });
    }
};
