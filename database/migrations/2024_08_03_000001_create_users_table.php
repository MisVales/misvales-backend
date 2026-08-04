<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('normalized_email')->unique();
            $table->string('password')->nullable();
            $table->string('webauthn_user_handle')->unique()->nullable();
            $table->string('state')->default('PENDING_ACTIVATION');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->timestampTz('mfa_enrolled_at')->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();
        });

        $this->agregarRestriccionesDeEstado();
    }

    private function agregarRestriccionesDeEstado(): void
    {
        DB::statement("
            ALTER TABLE users 
            ADD CONSTRAINT chk_user_state 
            CHECK (state IN ('PENDING_ACTIVATION', 'ACTIVE', 'LOCKED', 'DISABLED'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
