<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('created_by_user_id')->nullable();
            $table->string('purpose')->default('ACCOUNT_ACTIVATION');
            $table->string('token_hash')->unique();
            $table->string('exchange_token_hash')->unique()->nullable();
            $table->string('state')->default('ACTIVE');
            $table->timestampTz('expires_at');
            $table->timestampTz('inspected_at')->nullable();
            $table->timestampTz('prepared_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('exchange_expires_at')->nullable();
            $table->timestampTz('mfa_setup_completed_at')->nullable();
            $table->timestampTz('recovery_codes_confirmed_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        $this->agregarRestriccionesDeEstadoYProposito();
        $this->crearIndiceUnicoParcial();
    }

    private function agregarRestriccionesDeEstadoYProposito(): void
    {
        DB::statement("
            ALTER TABLE account_invitations 
            ADD CONSTRAINT chk_invitation_state 
            CHECK (state IN ('ACTIVE', 'PREPARED', 'CONSUMED', 'EXPIRED', 'REVOKED'))
        ");

        DB::statement("
            ALTER TABLE account_invitations 
            ADD CONSTRAINT chk_invitation_purpose 
            CHECK (purpose IN ('ACCOUNT_ACTIVATION'))
        ");
    }

    private function crearIndiceUnicoParcial(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('account_invitations', function (Blueprint $table) {
                $table->unsignedTinyInteger('active_unique')->nullable()->storedAs("IF(state IN ('ACTIVE', 'PREPARED'), 1, NULL)");
                $table->unique(['user_id', 'purpose', 'active_unique'], 'account_invitations_active_unique');
            });

            return;
        }

        DB::statement("
            CREATE UNIQUE INDEX account_invitations_active_unique 
            ON account_invitations (user_id, purpose) 
            WHERE state IN ('ACTIVE', 'PREPARED')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
