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

        Schema::create('client_transfer_requests', function (Blueprint $table) use ($isMysql): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignUuid('origin_assignment_id')->constrained('client_distributor_assignments')->restrictOnDelete();
            $table->foreignUuid('origin_distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->foreignUuid('destination_distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->foreignUuid('origin_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 32);
            if ($isMysql) {
                $table->unsignedTinyInteger('active_client_unique')
                    ->nullable()
                    ->storedAs("IF(status IN ('REQUESTED','PREACCEPTED','ORIGIN_AUTHORIZED'), 1, NULL)");
                $table->unique(['client_id', 'active_client_unique'], 'client_transfer_active_client_unique');
            }
            $table->foreignUuid('initiated_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('preaccepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('preaccepted_at')->nullable();
            $table->foreignUuid('origin_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('origin_decision_reason')->nullable();
            $table->timestampTz('origin_decided_at')->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('new_assignment_id')->nullable()->constrained('client_distributor_assignments')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['origin_distributor_id', 'status']);
            $table->index(['destination_distributor_id', 'status']);
        });

        Schema::create('organizational_change_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 48);
            $table->uuid('subject_id');
            $table->foreignUuid('origin_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('destination_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->jsonb('before_snapshot');
            $table->jsonb('after_snapshot');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['subject_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        DB::statement("ALTER TABLE client_transfer_requests ADD CONSTRAINT client_transfer_status_check CHECK (status IN ('REQUESTED','PREACCEPTED','ORIGIN_AUTHORIZED','COMPLETED','REJECTED_BY_RECEIVER','ORIGIN_REJECTED'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE client_transfer_requests ADD CONSTRAINT client_transfer_distinct_distributors_check CHECK (origin_distributor_id <> destination_distributor_id)');
        }
        if (! $isMysql) {
            DB::statement("CREATE UNIQUE INDEX client_transfer_active_client_unique ON client_transfer_requests (client_id) WHERE status IN ('REQUESTED','PREACCEPTED','ORIGIN_AUTHORIZED')");
        }
        DB::statement("ALTER TABLE organizational_change_events ADD CONSTRAINT organizational_change_type_check CHECK (type IN ('CLIENT_ADMIN_REASSIGNMENT','DISTRIBUTOR_BRANCH_CHANGE','COORDINATOR_CHANGE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_change_events');
        Schema::dropIfExists('client_transfer_requests');
    }
};
