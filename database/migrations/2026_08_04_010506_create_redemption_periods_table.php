<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemption_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status');
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        DB::statement('ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_dates CHECK (starts_at < ends_at);');
        DB::statement('ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_lock_version CHECK (lock_version >= 0);');
        DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_status CHECK (status IN ('DRAFT', 'PUBLISHED', 'CLOSED', 'CANCELLED'));");
    }

    public function down(): void
    {
        Schema::dropIfExists('redemption_periods');
    }
};

