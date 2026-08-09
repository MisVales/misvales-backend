<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('event_type');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampsTz();

            $table->index(['published_at', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_outbox_messages');
    }
};
