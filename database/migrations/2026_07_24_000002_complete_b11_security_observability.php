<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table): void {
            $table->uuid('event_uuid')->nullable()->unique();
            $table->string('event_type', 128)->nullable()->index();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('authorizer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('auth_session_id')->nullable()->constrained('auth_sessions')->nullOnDelete();
            $table->string('role_code', 64)->nullable();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('application', 64)->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('display_timezone', 64)->default('America/Monterrey');
            $table->string('ip_address', 45)->nullable();
            $table->string('device_id', 128)->nullable();
            $table->string('resource_type', 128)->nullable();
            $table->string('resource_id', 128)->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('risk_level', 16)->nullable();
            $table->unsignedInteger('counter')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
        });

        Schema::create('security_alerts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('security_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affected_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('severity', 16)->index();
            $table->string('type', 128);
            $table->string('state', 32)->default('OPEN')->index();
            $table->text('summary');
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('action_request_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'state', 'created_at']);
        });

        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->uuid('event_uuid')->nullable()->unique();
            $table->string('recipient')->nullable();
            $table->string('template', 128)->nullable();
            $table->string('state', 32)->default('PENDING')->index();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('result', 64)->nullable();
            $table->text('last_error')->nullable();
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_event_id')->constrained()->cascadeOnDelete();
            $table->string('recipient');
            $table->string('template', 128);
            $table->string('idempotency_key')->unique();
            $table->string('state', 32)->default('PENDING')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('result', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');

        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropColumn([
                'event_uuid',
                'recipient',
                'template',
                'state',
                'occurred_at',
                'next_attempt_at',
                'last_attempt_at',
                'result',
                'last_error',
            ]);
        });

        Schema::dropIfExists('security_alerts');

        Schema::table('security_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requester_user_id');
            $table->dropConstrainedForeignId('authorizer_user_id');
            $table->dropConstrainedForeignId('executor_user_id');
            $table->dropConstrainedForeignId('auth_session_id');
            $table->dropColumn([
                'event_uuid',
                'event_type',
                'role_code',
                'branch_id',
                'application',
                'occurred_at',
                'display_timezone',
                'ip_address',
                'device_id',
                'resource_type',
                'resource_id',
                'before_state',
                'after_state',
                'risk_level',
                'counter',
                'reason',
                'correlation_id',
            ]);
        });
    }
};
