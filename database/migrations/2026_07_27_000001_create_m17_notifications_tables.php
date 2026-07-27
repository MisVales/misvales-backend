<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. notification_events
        Schema::create('notification_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('outbox_event_id')->unique(); // Unique restraint for idempotency
            $table->string('event_code'); // EV-001 to EV-097
            $table->integer('event_version')->default(1);
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('authorizer_user_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->uuid('causation_id')->nullable();
            $table->timestamp('occurred_at');
            $table->jsonb('payload_snapshot')->nullable();
            $table->string('processing_status')->default('RECEIVED');
            $table->string('last_error_code')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps(); // created_at (reception), updated_at

            $table->index(['processing_status', 'created_at']);
            $table->index(['event_code', 'occurred_at']);
        });

        // 2. notification_recipients
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_event_id');
            $table->string('recipient_key');
            $table->string('recipient_type'); // USER, APPLICANT, etc.
            $table->uuid('user_id')->nullable();
            $table->uuid('application_id')->nullable();
            $table->string('email_snapshot')->nullable();
            $table->string('role_snapshot')->nullable();
            $table->uuid('branch_id_snapshot')->nullable();
            $table->json('resolution_reasons')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('notification_event_id')->references('id')->on('notification_events')->onDelete('cascade');
            $table->unique(['notification_event_id', 'recipient_key'], 'uniq_event_recipient');
        });

        // 3. notifications (in-app bandeja)
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_event_id');
            $table->uuid('notification_recipient_id');
            $table->uuid('user_id');
            $table->string('event_code');
            $table->string('title');
            $table->text('summary');
            $table->integer('template_version')->default(1);
            $table->string('target_type')->nullable();
            $table->uuid('target_id')->nullable();
            $table->string('status')->default('UNREAD');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('notification_event_id')->references('id')->on('notification_events')->onDelete('cascade');
            $table->foreign('notification_recipient_id')->references('id')->on('notification_recipients')->onDelete('cascade');
            $table->unique(['notification_event_id', 'user_id'], 'uniq_event_user_notification');

            $table->index(['user_id', 'status', 'occurred_at', 'id'], 'idx_user_status_occurred');
            $table->index(['user_id', 'occurred_at', 'id'], 'idx_user_occurred');
        });

        // 4. email_deliveries
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_event_id');
            $table->uuid('notification_recipient_id');
            $table->string('event_code');
            $table->string('recipient_email_snapshot');
            $table->string('subject_snapshot');
            $table->integer('template_version')->default(1);
            $table->jsonb('render_context_snapshot')->nullable();
            $table->string('message_key')->unique();
            $table->string('status')->default('PENDING');
            $table->integer('attempt_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();

            $table->foreign('notification_event_id')->references('id')->on('notification_events')->onDelete('cascade');
            $table->foreign('notification_recipient_id')->references('id')->on('notification_recipients')->onDelete('cascade');
            $table->unique(['notification_event_id', 'notification_recipient_id'], 'uniq_event_recipient_email');

            $table->index(['status', 'updated_at']);
        });

        // 5. email_delivery_attempts
        Schema::create('email_delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('email_delivery_id');
            $table->integer('attempt_number');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->string('result')->nullable(); // SUCCESS, RETRYABLE_FAILURE, PERMANENT_FAILURE
            $table->string('provider_message_id')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->foreign('email_delivery_id')->references('id')->on('email_deliveries')->onDelete('cascade');
            $table->unique(['email_delivery_id', 'attempt_number'], 'uniq_delivery_attempt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_delivery_attempts');
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notification_events');
    }
};
