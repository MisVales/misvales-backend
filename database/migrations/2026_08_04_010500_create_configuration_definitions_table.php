<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('value_type');
            $table->string('unit')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_sensitive')->default(false);
            $table->string('status');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'key']);
        });

        DB::statement('ALTER TABLE configuration_definitions ADD CONSTRAINT chk_cd_lock_version CHECK (lock_version >= 0);');
        DB::statement("ALTER TABLE configuration_definitions ADD CONSTRAINT chk_cd_value_type CHECK (value_type IN ('INTEGER', 'DECIMAL', 'PERCENTAGE', 'BOOLEAN', 'STRING', 'TIME', 'TIMEZONE', 'DURATION', 'JSON'));");
        DB::statement("ALTER TABLE configuration_definitions ADD CONSTRAINT chk_cd_status CHECK (status IN ('ACTIVE', 'INACTIVE'));");
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_definitions');
    }
};

