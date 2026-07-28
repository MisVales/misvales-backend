<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. audit_events (Inserción única, inmutable)
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_code');
            $table->integer('event_version')->default(1);
            $table->string('category');
            $table->timestampTz('occurred_at');
            $table->timestamp('business_datetime')->nullable();
            $table->uuid('requester_user_id')->nullable();
            $table->uuid('authorizer_user_id')->nullable();
            $table->uuid('executor_user_id')->nullable();
            $table->string('process_code')->nullable();
            $table->string('role_snapshot')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('device_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent_summary')->nullable();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('subject_public_number')->nullable();
            $table->string('action');
            $table->string('result');
            $table->jsonb('changed_fields')->nullable();
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->string('reason_code')->nullable();
            $table->text('reason_text')->nullable();
            $table->string('rule_code')->nullable();
            $table->integer('rule_version')->nullable();
            $table->jsonb('evidence_file_ids')->nullable();
            $table->string('request_id')->nullable();
            $table->string('trace_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->uuid('causation_id')->nullable();
            $table->string('idempotency_key_hash')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // NO updated_at, NO deleted_at

            $table->index(['event_code', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['correlation_id']);
        });

        // 2. process_runs
        Schema::create('process_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('process_code');
            $table->string('business_identifier')->nullable();
            $table->string('status')->default('PENDING');
            $table->integer('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->string('request_id')->nullable();
            $table->string('trace_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('counters')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['process_code', 'status']);
        });

        // 3. media_files
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('public_number')->nullable()->unique();
            $table->string('file_type');
            $table->string('status')->default('PENDING_UPLOAD');
            $table->string('storage_disk');
            $table->string('storage_key');
            $table->string('temporary_storage_key')->nullable();
            $table->string('original_name')->nullable();
            $table->string('safe_display_name')->nullable();
            $table->string('declared_extension')->nullable();
            $table->string('detected_extension')->nullable();
            $table->string('declared_mime')->nullable();
            $table->string('detected_mime')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('validated_by_process')->nullable();
            $table->string('rejection_code')->nullable();
            $table->text('rejection_detail')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // 4. media_file_bindings
        Schema::create('media_file_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('file_id');
            $table->string('owner_module');
            $table->string('owner_type');
            $table->uuid('owner_id');
            $table->string('purpose');
            $table->integer('version_number')->default(1);
            $table->boolean('is_current')->default(true);
            $table->uuid('bound_by')->nullable();
            $table->timestamp('bound_at')->useCurrent();
            $table->uuid('superseded_by_binding_id')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->foreign('file_id')->references('id')->on('media_files')->onDelete('cascade');
            $table->index(['owner_type', 'owner_id', 'purpose']);
        });

        // 5. file_upload_intents
        Schema::create('file_upload_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id');
            $table->uuid('branch_id')->nullable();
            $table->string('owner_module');
            $table->string('owner_type');
            $table->uuid('owner_id');
            $table->string('purpose');
            $table->string('technical_policy');
            $table->string('idempotency_key_hash')->nullable();
            $table->string('status')->default('PENDING');
            $table->timestamp('expires_at');
            $table->uuid('result_file_id')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('result_file_id')->references('id')->on('media_files')->onDelete('set null');
        });

        // 6. file_validation_attempts
        Schema::create('file_validation_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('file_id');
            $table->integer('attempt_number');
            $table->string('job_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('detected_mime')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->jsonb('verifications_executed')->nullable();
            $table->string('result')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('media_files')->onDelete('cascade');
        });

        // 7. file_access_grants
        Schema::create('file_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('file_id');
            $table->uuid('actor_user_id');
            $table->string('action_allowed');
            $table->string('checked_resource_type')->nullable();
            $table->uuid('checked_resource_id')->nullable();
            $table->timestamp('expires_at');
            $table->string('status')->default('ACTIVE');
            $table->string('correlation_id')->nullable();
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('media_files')->onDelete('cascade');
            $table->index(['actor_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_access_grants');
        Schema::dropIfExists('file_validation_attempts');
        Schema::dropIfExists('file_upload_intents');
        Schema::dropIfExists('media_file_bindings');
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('process_runs');
        Schema::dropIfExists('audit_events');
    }
};
