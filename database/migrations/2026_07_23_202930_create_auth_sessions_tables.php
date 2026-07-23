<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('application', 64);
            $table->string('device_id', 128)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('context_version')->default(1);
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('state', 32)->default('ACTIVE');
            $table->timestamps();

            $table->index(['user_id', 'state', 'expires_at']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auth_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->uuid('family_id')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('auth_session_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['auth_session_id']);
            $table->dropColumn('auth_session_id');
        });

        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('auth_sessions');
    }
};
