<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordinator_distributor_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('distributor_id')->constrained('users');
            $table->foreignId('coordinator_user_id')->constrained('users');
            $table->foreignId('branch_id')->constrained();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('assigned_by')->constrained('users');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordinator_distributor_assignments');
    }
};