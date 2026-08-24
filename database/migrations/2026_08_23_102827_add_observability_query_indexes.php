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
            $table->index(['branch_id', 'created_at'], 'audit_logs_branch_created_idx');
            $table->index(['event_name', 'created_at'], 'audit_logs_event_created_idx');
            $table->index(['actor_role', 'created_at'], 'audit_logs_role_created_idx');
            $table->index(['result', 'created_at'], 'audit_logs_result_created_idx');
        });

        Schema::table('operational_logs', function (Blueprint $table): void {
            $table->index(['branch_id', 'occurred_at'], 'operational_logs_branch_occurred_idx');
            $table->index(['level', 'occurred_at'], 'operational_logs_level_occurred_idx');
            $table->index(['status_code', 'occurred_at'], 'operational_logs_status_occurred_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_logs', function (Blueprint $table): void {
            $table->dropIndex('operational_logs_branch_occurred_idx');
            $table->dropIndex('operational_logs_level_occurred_idx');
            $table->dropIndex('operational_logs_status_occurred_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_branch_created_idx');
            $table->dropIndex('audit_logs_event_created_idx');
            $table->dropIndex('audit_logs_role_created_idx');
            $table->dropIndex('audit_logs_result_created_idx');
        });
    }
};
