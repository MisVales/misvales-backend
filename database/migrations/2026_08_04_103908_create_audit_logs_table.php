<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('actor_role')->nullable();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('entity_type')->index();
            $table->uuid('entity_id')->nullable()->index();
            $table->string('version')->nullable();
            $table->jsonb('previous_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->string('result')->default('SUCCESS');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
