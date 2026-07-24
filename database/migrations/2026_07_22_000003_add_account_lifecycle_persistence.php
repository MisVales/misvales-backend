<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('credential_version')->default(1)->after('context_version');
            $table->timestampTz('invited_at')->nullable()->after('email_verified_at');
            $table->timestampTz('activated_at')->nullable()->after('invited_at');
        });

        Schema::table('account_requests', function (Blueprint $table) {
            $table->string('target_name')->nullable()->after('target_email');
            $table->foreignId('result_user_id')->nullable()->after('decision_reason')->constrained('users')->restrictOnDelete();
        });

        Schema::table('account_invitations', function (Blueprint $table) {
            $table->string('email_hash', 64)->nullable()->after('purpose');
            $table->unsignedBigInteger('credential_version')->nullable()->after('email_hash');
        });

        DB::table('account_invitations')
            ->join('users', 'users.id', '=', 'account_invitations.user_id')
            ->select(['account_invitations.id', 'users.normalized_email', 'users.credential_version'])
            ->orderBy('account_invitations.id')
            ->each(function (object $invitation): void {
                DB::table('account_invitations')->where('id', $invitation->id)->update([
                    'email_hash' => hash('sha256', $invitation->normalized_email),
                    'credential_version' => $invitation->credential_version,
                ]);
            });

        Schema::table('account_invitations', function (Blueprint $table) {
            $table->string('email_hash', 64)->nullable(false)->change();
            $table->unsignedBigInteger('credential_version')->nullable(false)->change();
        });

        Schema::create('processed_domain_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 150);
            $table->string('event_key', 150)->unique();
            $table->timestampTz('processed_at');
            $table->unique(['event_type', 'event_key']);
        });

        Schema::create('distributor_access_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('external_request_id', 100)->unique();
            $table->string('external_distributor_id', 100)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('coordinator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->decimal('initial_credit_line', 14, 2);
            $table->timestampTz('authorized_at');
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX account_invitations_one_active_purpose
                ON account_invitations (user_id, purpose) WHERE state = 'ACTIVE';
                ALTER TABLE account_requests ADD CONSTRAINT account_requests_type_check
                CHECK (type IN ('CREATE', 'DISABLE', 'REACTIVATE', 'RECOVERY'));
                ALTER TABLE account_invitations ADD CONSTRAINT account_invitations_purpose_check
                CHECK (purpose IN ('ACCOUNT_ACTIVATION', 'ACCOUNT_REACTIVATION', 'ACCOUNT_RECOVERY'));
                ALTER TABLE distributor_access_links ADD CONSTRAINT distributor_credit_line_non_negative
                CHECK (initial_credit_line >= 0);

                CREATE OR REPLACE FUNCTION prevent_terminal_record_reuse() RETURNS trigger AS $$
                BEGIN
                    IF TG_TABLE_NAME = 'account_requests' THEN
                        IF OLD.state IN ('APPROVED', 'REJECTED', 'CANCELLED') THEN RAISE EXCEPTION 'A decided request is immutable'; END IF;
                    ELSIF TG_TABLE_NAME = 'account_invitations' THEN
                        IF OLD.state IN ('USED', 'EXPIRED', 'REVOKED') THEN RAISE EXCEPTION 'A terminal invitation cannot be reused'; END IF;
                    ELSIF TG_TABLE_NAME = 'refresh_tokens' THEN
                        IF OLD.state IN ('USED', 'REPLACED', 'EXPIRED', 'REVOKED') THEN RAISE EXCEPTION 'A terminal refresh token cannot become active again'; END IF;
                    ELSIF TG_TABLE_NAME = 'refresh_token_families' THEN
                        IF OLD.state IN ('EXPIRED', 'REVOKED') THEN RAISE EXCEPTION 'A terminal refresh family cannot become active again'; END IF;
                    ELSIF TG_TABLE_NAME IN ('reauth_authorizations', 'operational_authorization_tokens') THEN
                        IF OLD.used_at IS NOT NULL OR OLD.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'A consumed authorization cannot be reused'; END IF;
                    ELSIF TG_TABLE_NAME = 'mfa_recovery_codes' THEN
                        IF OLD.used_at IS NOT NULL OR OLD.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'A recovery code cannot be reused'; END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION enforce_account_state_transition() RETURNS trigger AS $$
                BEGIN
                    IF NEW.state IS NOT DISTINCT FROM OLD.state THEN RETURN NEW; END IF;
                    IF NOT (
                        (OLD.state = 'PENDING_ACTIVATION' AND NEW.state IN ('ACTIVE', 'DISABLED'))
                        OR (OLD.state = 'ACTIVE' AND NEW.state IN ('SECURITY_SUSPENDED', 'DISABLED'))
                        OR (OLD.state = 'SECURITY_SUSPENDED' AND NEW.state IN ('PENDING_ACTIVATION', 'DISABLED'))
                        OR (OLD.state = 'DISABLED' AND NEW.state = 'PENDING_ACTIVATION')
                    ) THEN
                        RAISE EXCEPTION 'Invalid account state transition: % to %', OLD.state, NEW.state;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION protect_last_general_manager() RETURNS trigger AS $$
                DECLARE general_manager_role_id bigint;
                DECLARE remaining_count integer;
                BEGIN
                    SELECT id INTO general_manager_role_id FROM roles WHERE code = 'GENERAL_MANAGER' FOR UPDATE;
                    IF OLD.role_id = general_manager_role_id AND OLD.state = 'ACTIVE' AND NEW.state <> 'ACTIVE' THEN
                        SELECT count(*) INTO remaining_count FROM users
                        WHERE role_id = general_manager_role_id AND state = 'ACTIVE' AND id <> OLD.id;
                        IF remaining_count = 0 THEN RAISE EXCEPTION 'The last active general manager cannot be disabled'; END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER users_account_state_transition BEFORE UPDATE OF state ON users
                FOR EACH ROW EXECUTE FUNCTION enforce_account_state_transition();
                CREATE TRIGGER users_last_general_manager BEFORE UPDATE OF state ON users
                FOR EACH ROW EXECUTE FUNCTION protect_last_general_manager();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX IF EXISTS account_invitations_one_active_purpose');
            DB::unprepared('ALTER TABLE account_requests DROP CONSTRAINT IF EXISTS account_requests_type_check');
            DB::unprepared('ALTER TABLE account_invitations DROP CONSTRAINT IF EXISTS account_invitations_purpose_check');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_last_general_manager() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_account_state_transition() CASCADE');
        }

        Schema::dropIfExists('distributor_access_links');
        Schema::dropIfExists('processed_domain_events');

        Schema::table('account_invitations', function (Blueprint $table) {
            $table->dropColumn(['email_hash', 'credential_version']);
        });
        Schema::table('account_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('result_user_id');
            $table->dropColumn('target_name');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['credential_version', 'invited_at', 'activated_at']);
        });
    }
};
