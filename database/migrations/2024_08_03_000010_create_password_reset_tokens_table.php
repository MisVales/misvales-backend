<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('token_hash')->unique();
            $table->ipAddress('requested_ip')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        $this->crearIndiceUnicoParcial();
    }

    private function crearIndiceUnicoParcial(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->unsignedTinyInteger('active_unique')->nullable()->storedAs('IF(consumed_at IS NULL AND revoked_at IS NULL, 1, NULL)');
                $table->unique(['user_id', 'active_unique'], 'password_reset_tokens_active_unique');
            });

            return;
        }

        DB::statement('
            CREATE UNIQUE INDEX password_reset_tokens_active_unique 
            ON password_reset_tokens (user_id) 
            WHERE consumed_at IS NULL AND revoked_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
