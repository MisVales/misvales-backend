<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('run_number', 40)->unique();
            $table->string('report_code', 80);
            $table->unsignedSmallInteger('contract_version');
            $table->string('status', 16);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('requested_role', 40);
            $table->string('scope_type', 20);
            $table->json('scope_snapshot');
            $table->json('filters_json');
            $table->char('filters_hash', 64);
            $table->string('idempotency_key', 128);
            $table->timestampTz('queued_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('as_of')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->string('result_location', 255)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->uuid('correlation_id');
            $table->timestampsTz();
            $table->unique(['requested_by', 'idempotency_key'], 'report_runs_actor_idempotency_unique');
            $table->index(['requested_by', 'created_at']);
            $table->index(['status', 'started_at']);
            $table->index(['report_code', 'created_at']);
        });

        Schema::create('report_run_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_run_id')->constrained('report_runs')->cascadeOnDelete();
            $table->unsignedInteger('block_number');
            $table->unsignedInteger('row_count');
            $table->text('payload_protected');
            $table->char('payload_hash', 64);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['report_run_id', 'block_number']);
        });

        Schema::create('report_query_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->string('report_code', 80)->nullable();
            $table->string('scope_type', 20)->nullable();
            $table->char('filters_hash', 64)->nullable();
            $table->string('outcome', 20);
            $table->unsignedBigInteger('rows_returned')->nullable();
            $table->string('session_id', 255)->nullable();
            $table->uuid('run_id')->nullable();
            $table->uuid('correlation_id');
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['report_code', 'occurred_at']);
        });

        Schema::create('report_outbox_events', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->string('event_name', 80);
            $table->uuid('aggregate_id')->nullable();
            $table->string('report_code', 80)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope_type', 20)->nullable();
            $table->uuid('correlation_id');
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['published_at', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_status_check CHECK (status IN ('QUEUED','RUNNING','COMPLETED','FAILED','EXPIRED'))");
            DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_scope_check CHECK (scope_type IN ('GLOBAL','BRANCH','COORDINATOR','DISTRIBUTOR'))");
            DB::statement("ALTER TABLE report_query_events ADD CONSTRAINT report_query_events_outcome_check CHECK (outcome IN ('ALLOWED','DENIED'))");
            DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_terminal_dates_check CHECK (
                (status = 'QUEUED' AND started_at IS NULL AND completed_at IS NULL AND failed_at IS NULL)
                OR (status = 'RUNNING' AND started_at IS NOT NULL AND completed_at IS NULL AND failed_at IS NULL)
                OR (status = 'COMPLETED' AND started_at IS NOT NULL AND completed_at IS NOT NULL AND failed_at IS NULL)
                OR (status = 'FAILED' AND failed_at IS NOT NULL AND completed_at IS NULL)
                OR (status = 'EXPIRED' AND (completed_at IS NOT NULL OR failed_at IS NOT NULL))
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_outbox_events');
        Schema::dropIfExists('report_query_events');
        Schema::dropIfExists('report_run_results');
        Schema::dropIfExists('report_runs');
    }
};
