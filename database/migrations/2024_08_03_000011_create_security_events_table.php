<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('auth_session_id')->nullable();
            $table->string('event_type');
            $table->string('severity');
            $table->string('outcome');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('request_id')->nullable();
            $table->string('trace_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('auth_session_id')->references('id')->on('auth_sessions')->onDelete('set null');

            $table->index(['user_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['branch_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
            $table->index(['request_id']);
            $table->index(['trace_id']);
        });

        $this->agregarRestricciones();
    }

    private function agregarRestricciones(): void
    {
        DB::statement("
            ALTER TABLE security_events 
            ADD CONSTRAINT chk_security_event_severity 
            CHECK (severity IN ('INFO', 'WARNING', 'CRITICAL'))
        ");

        DB::statement("
            ALTER TABLE security_events 
            ADD CONSTRAINT chk_security_event_outcome 
            CHECK (outcome IN ('SUCCESS', 'FAILURE', 'DENIED'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
