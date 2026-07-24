<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_exchanges', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('account_invitation_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('prepared_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['account_invitation_id', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE account_invitations DROP CONSTRAINT account_invitations_purpose_check;
                ALTER TABLE account_invitations ADD CONSTRAINT account_invitations_purpose_check
                CHECK (purpose IN ('ACCOUNT_ACTIVATION', 'ACCOUNT_REACTIVATION', 'ACCOUNT_RECOVERY', 'PASSWORD_RECOVERY'));
                CREATE UNIQUE INDEX invitation_exchanges_one_active
                ON invitation_exchanges (account_invitation_id) WHERE used_at IS NULL AND revoked_at IS NULL;
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_exchanges');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX IF EXISTS invitation_exchanges_one_active');
            DB::unprepared('ALTER TABLE account_invitations DROP CONSTRAINT IF EXISTS account_invitations_purpose_check');
            DB::table('account_invitations')->where('purpose', 'PASSWORD_RECOVERY')->delete();
            DB::unprepared(<<<'SQL'
                ALTER TABLE account_invitations ADD CONSTRAINT account_invitations_purpose_check
                CHECK (purpose IN ('ACCOUNT_ACTIVATION', 'ACCOUNT_REACTIVATION', 'ACCOUNT_RECOVERY'));
                SQL);
        }
    }
};
