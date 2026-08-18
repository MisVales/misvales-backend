<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type', 24);
            $table->uuid('source_id');
            $table->string('event_type');
            $table->foreignUuid('recipient_id')->constrained('users')->restrictOnDelete();
            $table->uuid('notification_id');
            $table->string('channels', 64);
            $table->timestampTz('delivered_at');
            $table->timestampsTz();
            $table->unique(['source_type', 'source_id', 'event_type', 'recipient_id'], 'notification_delivery_idempotency');
            $table->index(['recipient_id', 'delivered_at']);
        });

        Schema::create('operational_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('channel', 24);
            $table->string('level', 16);
            $table->string('event');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('request_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->string('trace_id')->nullable();
            $table->string('method', 12)->nullable();
            $table->string('path', 512)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['channel', 'occurred_at']);
            $table->index(['request_id']);
            $table->index(['correlation_id']);
            $table->index(['trace_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->uuid('authorizer_id')->nullable()->index();
            $table->uuid('executor_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('correlation_id')->nullable()->index();
            $table->jsonb('evidence')->nullable();
            $table->jsonb('rule_snapshot')->nullable();
        });

        DB::statement("ALTER TABLE operational_logs ADD CONSTRAINT operational_log_channel_check CHECK (channel IN ('APPLICATION','SECURITY','OPERATION','ERROR','AUDIT'))");
        foreach (['audit_logs', 'operational_logs', 'notification_deliveries'] as $table) {
            if (DB::getDriverName() !== 'sqlite') {
            if (DB::getDriverName() !== 'sqlite') {
            DB::statement("CREATE OR REPLACE FUNCTION prevent_{$table}_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION '{$table} es inmutable'; END; $$ LANGUAGE plpgsql");
            DB::statement("CREATE TRIGGER trg_prevent_{$table}_update_delete BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_{$table}_mutation() ");
        }
        }
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'operational_logs', 'notification_deliveries'] as $table) {
            if (DB::getDriverName() !== 'sqlite') {
            DB::statement("DROP TRIGGER IF EXISTS trg_prevent_{$table}_update_delete ON {$table}");
            DB::statement("DROP FUNCTION IF EXISTS prevent_{$table}_mutation()");
        }
        }
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn(['authorizer_id', 'executor_id', 'session_id', 'correlation_id', 'evidence', 'rule_snapshot']);
        });
        Schema::dropIfExists('operational_logs');
        Schema::dropIfExists('notification_deliveries');
    }
};

