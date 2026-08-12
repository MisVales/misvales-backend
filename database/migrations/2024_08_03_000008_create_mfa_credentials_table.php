<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type');
            $table->string('label')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            // TOTP fields
            $table->text('secret_ciphertext')->nullable();
            $table->string('algorithm')->nullable();
            $table->unsignedInteger('digits')->nullable();
            $table->unsignedInteger('period')->nullable();

            // Passkey fields
            $table->string('credential_identifier')->nullable();
            $table->text('public_key')->nullable();
            $table->unsignedBigInteger('sign_count')->nullable();
            $table->json('transports')->nullable();
            $table->string('aaguid')->nullable();
            $table->string('attestation_format')->nullable();
            $table->string('rp_id')->nullable();
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backup_state')->nullable();

            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        $this->agregarRestriccionesYValidaciones();
    }

    private function agregarRestriccionesYValidaciones(): void
    {
        DB::statement("
            ALTER TABLE mfa_credentials 
            ADD CONSTRAINT chk_mfa_type 
            CHECK (type IN ('TOTP', 'PASSKEY'))
        ");

        DB::statement("
            ALTER TABLE mfa_credentials 
            ADD CONSTRAINT chk_mfa_totp_fields 
            CHECK (
                (type = 'TOTP' AND secret_ciphertext IS NOT NULL) OR 
                (type <> 'TOTP')
            )
        ");

        DB::statement("
            ALTER TABLE mfa_credentials 
            ADD CONSTRAINT chk_mfa_passkey_fields 
            CHECK (
                (type = 'PASSKEY' AND credential_identifier IS NOT NULL AND public_key IS NOT NULL) OR 
                (type <> 'PASSKEY')
            )
        ");

        // global unique credential identifier
        DB::statement('
            CREATE UNIQUE INDEX mfa_credentials_identifier_unique 
            ON mfa_credentials (credential_identifier) 
            WHERE credential_identifier IS NOT NULL
        ');

        // unique active totp per user
        DB::statement("
            CREATE UNIQUE INDEX mfa_credentials_totp_active_unique 
            ON mfa_credentials (user_id, type) 
            WHERE type = 'TOTP' AND revoked_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_credentials');
    }
};
