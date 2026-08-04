<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('module');
            $table->string('action');
            $table->string('description')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['module']);
            $table->index(['action']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
