<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->string('validation_status', 16)->default('VALIDATED');
            $table->timestampTz('validated_at')->nullable();
        });
        Schema::create('operational_heartbeats', function (Blueprint $table): void {
            $table->string('component')->primary();
            $table->timestampTz('last_seen_at');
            $table->jsonb('metadata')->nullable();
        });
        DB::statement("ALTER TABLE media_files ADD CONSTRAINT media_validation_status_check CHECK (validation_status IN ('TEMPORARY','VALIDATED','REJECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_heartbeats');
        Schema::table('media_files', fn (Blueprint $table) => $table->dropColumn(['validation_status', 'validated_at']));
    }
};

