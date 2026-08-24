<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_visits', function (Blueprint $table): void {
            $table->timestampTz('scheduled_for')->nullable()->after('assigned_at');
            $table->index(['verifier_id', 'scheduled_for'], 'verification_visits_verifier_schedule_index');
        });
    }

    public function down(): void
    {
        Schema::table('verification_visits', function (Blueprint $table): void {
            $table->dropIndex('verification_visits_verifier_schedule_index');
            $table->dropColumn('scheduled_for');
        });
    }
};
