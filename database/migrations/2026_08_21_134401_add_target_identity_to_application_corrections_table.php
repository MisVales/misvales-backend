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
        Schema::table('application_corrections', function (Blueprint $table) {
            $table->uuid('target_record_id')->nullable()->after('field_path');
            $table->unsignedSmallInteger('difference_index')->nullable()->after('target_record_id');
            $table->index(['verification_visit_id', 'difference_index'], 'app_corr_visit_difference_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_corrections', function (Blueprint $table) {
            $table->dropIndex(['verification_visit_id', 'difference_index']);
            $table->dropColumn(['target_record_id', 'difference_index']);
        });
    }
};
