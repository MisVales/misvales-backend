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
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE audit_logs ADD COLUMN event_type VARCHAR(255) GENERATED ALWAYS AS (event_name) STORED');
        DB::statement('CREATE INDEX audit_logs_event_type_index ON audit_logs (event_type)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_logs_event_type_index ON audit_logs');
        DB::statement('ALTER TABLE audit_logs DROP COLUMN IF EXISTS event_type');

        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
