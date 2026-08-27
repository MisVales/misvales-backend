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
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('created_at', 'audit_logs_created_at_idx');
            $table->index(['entity_type', 'created_at'], 'audit_logs_entity_created_idx');
            $table->index(['actor_id', 'created_at'], 'audit_logs_actor_created_idx');
        });

        Schema::table('operational_logs', function (Blueprint $table): void {
            $table->index('occurred_at', 'operational_logs_occurred_at_idx');
            $table->index(['event', 'occurred_at'], 'operational_logs_event_occurred_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_logs', function (Blueprint $table): void {
            $table->dropIndex('operational_logs_occurred_at_idx');
            $table->dropIndex('operational_logs_event_occurred_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_created_at_idx');
            $table->dropIndex('audit_logs_entity_created_idx');
            $table->dropIndex('audit_logs_actor_created_idx');
        });
    }
};
