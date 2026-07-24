<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->after('id');
            $table->string('normalized_email')->nullable()->after('email');
            $table->string('state', 30)->default('PENDING_ACTIVATION')->after('context_version');
            $table->timestampTz('password_changed_at')->nullable();
            $table->timestampTz('mfa_enrolled_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('security_suspended_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
        });

        DB::table('users')->select(['id', 'email'])->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'public_id' => (string) Str::uuid(),
                'normalized_email' => mb_strtolower(trim($user->email)),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->nullable(false)->change();
            $table->string('normalized_email')->nullable(false)->change();
            $table->unique('public_id');
            $table->unique('normalized_email');
            $table->index(['state', 'role_id']);
            $table->string('password')->nullable()->change();
        });

        Schema::create('account_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('type', 30);
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('target_email')->nullable();
            $table->foreignId('requested_role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('state', 30)->default('PENDING_APPROVAL');
            $table->string('decision', 20)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestampsTz();
            $table->index(['requested_by', 'state']);
            $table->index(['branch_id', 'state']);
        });

        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 30);
            $table->string('token_hash', 64)->unique();
            $table->string('state', 20)->default('ACTIVE');
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'state']);
        });

        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('password_hash');
            $table->timestampTz('recorded_at')->index();
            $table->index(['user_id', 'recorded_at']);
        });

        Schema::create('mfa_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('credential_identifier', 255);
            $table->text('public_key')->nullable();
            $table->text('encrypted_secret')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('state', 20)->default('ACTIVE');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'type', 'credential_identifier']);
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestampTz('issued_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['user_id', 'used_at']);
        });

        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('application', 40);
            $table->string('device_id', 255);
            $table->string('device_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('last_activity_at')->index();
            $table->timestampTz('expires_at')->index();
            $table->string('state', 20)->default('ACTIVE');
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('context_version');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'state', 'last_activity_at']);
        });

        Schema::create('refresh_token_families', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('auth_session_id')->constrained()->restrictOnDelete();
            $table->string('application', 40);
            $table->string('state', 20)->default('ACTIVE');
            $table->timestampTz('absolute_expires_at')->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'auth_session_id']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refresh_token_family_id')->constrained()->restrictOnDelete();
            $table->foreignId('auth_session_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('state', 20)->default('ACTIVE');
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('replaced_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('replaced_by_id')->nullable()->constrained('refresh_tokens')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['refresh_token_family_id', 'auth_session_id'], 'refresh_tokens_family_session_fk')
                ->references(['id', 'auth_session_id'])->on('refresh_token_families')->restrictOnDelete();
            $table->index(['refresh_token_family_id', 'state']);
        });

        Schema::create('auth_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('identifier_hash', 64);
            $table->string('factor', 30);
            $table->string('ip_address', 45)->nullable();
            $table->string('device_id', 255)->nullable();
            $table->string('application', 40);
            $table->timestampTz('window_started_at');
            $table->string('result', 30);
            $table->timestampTz('occurred_at')->index();
            $table->index(['identifier_hash', 'factor', 'window_started_at']);
            $table->index(['ip_address', 'window_started_at']);
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('auth_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('rule_code', 100);
            $table->string('scope', 30);
            $table->string('result', 30);
            $table->uuid('correlation_id');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->index(['target_user_id', 'occurred_at']);
            $table->index(['rule_code', 'occurred_at']);
            $table->index('correlation_id');
        });

        Schema::create('reauth_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('auth_session_id')->constrained()->restrictOnDelete();
            $table->string('action', 100);
            $table->string('record_type', 100)->nullable();
            $table->string('record_id', 100)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('operational_authorization_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('record_type', 100);
            $table->string('record_id', 100);
            $table->jsonb('authorized_fields');
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('type', 150);
            $table->jsonb('payload');
            $table->string('idempotency_key', 150)->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('state', 20)->default('PENDING');
            $table->timestampTz('available_at')->index();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['state', 'available_at']);
        });

        $this->addPostgreSqlConstraints();
    }

    private function addPostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state IN ('PENDING_ACTIVATION', 'ACTIVE', 'SECURITY_SUSPENDED', 'DISABLED'));
            ALTER TABLE account_requests ADD CONSTRAINT account_requests_state_check CHECK (state IN ('PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'CANCELLED'));
            ALTER TABLE account_requests ADD CONSTRAINT account_requests_decision_check CHECK (
                (state = 'PENDING_APPROVAL' AND decision IS NULL AND decided_by IS NULL AND decided_at IS NULL)
                OR (state IN ('APPROVED', 'REJECTED') AND decision = state AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND decision_reason IS NOT NULL)
                OR state = 'CANCELLED'
            );
            ALTER TABLE account_invitations ADD CONSTRAINT account_invitations_state_check CHECK (state IN ('ACTIVE', 'USED', 'EXPIRED', 'REVOKED'));
            ALTER TABLE mfa_credentials ADD CONSTRAINT mfa_credentials_type_check CHECK (type IN ('PASSKEY', 'TOTP'));
            ALTER TABLE mfa_credentials ADD CONSTRAINT mfa_credentials_material_check CHECK (
                (type = 'PASSKEY' AND public_key IS NOT NULL AND encrypted_secret IS NULL)
                OR (type = 'TOTP' AND encrypted_secret IS NOT NULL AND public_key IS NULL)
            );
            ALTER TABLE auth_sessions ADD CONSTRAINT auth_sessions_state_check CHECK (state IN ('ACTIVE', 'EXPIRED', 'REVOKED'));
            ALTER TABLE refresh_token_families ADD CONSTRAINT refresh_token_families_state_check CHECK (state IN ('ACTIVE', 'EXPIRED', 'REVOKED'));
            ALTER TABLE refresh_tokens ADD CONSTRAINT refresh_tokens_state_check CHECK (state IN ('ACTIVE', 'USED', 'REPLACED', 'EXPIRED', 'REVOKED'));
            ALTER TABLE reauth_authorizations ADD CONSTRAINT reauth_exact_lifetime CHECK (expires_at = issued_at + interval '5 minutes');
            ALTER TABLE operational_authorization_tokens ADD CONSTRAINT operational_authorization_exact_lifetime CHECK (expires_at = issued_at + interval '5 minutes');

            CREATE UNIQUE INDEX refresh_families_one_active_per_session ON refresh_token_families (auth_session_id) WHERE state = 'ACTIVE';
            CREATE UNIQUE INDEX refresh_tokens_one_active_per_family ON refresh_tokens (refresh_token_family_id) WHERE state = 'ACTIVE';
            CREATE INDEX account_requests_pending_idx ON account_requests (branch_id, created_at) WHERE state = 'PENDING_APPROVAL';

            CREATE OR REPLACE FUNCTION enforce_three_active_sessions() RETURNS trigger AS $$
            DECLARE active_count integer;
            BEGIN
                IF NEW.state = 'ACTIVE' THEN
                    PERFORM id FROM users WHERE id = NEW.user_id FOR UPDATE;
                    SELECT count(*) INTO active_count FROM auth_sessions
                    WHERE user_id = NEW.user_id AND state = 'ACTIVE' AND id IS DISTINCT FROM NEW.id;
                    IF active_count >= 3 THEN RAISE EXCEPTION 'A user cannot have more than three active sessions'; END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER auth_sessions_max_three BEFORE INSERT OR UPDATE OF state, user_id ON auth_sessions
            FOR EACH ROW EXECUTE FUNCTION enforce_three_active_sessions();

            CREATE OR REPLACE FUNCTION prevent_terminal_record_reuse() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'account_requests' AND OLD.state IN ('APPROVED', 'REJECTED', 'CANCELLED') THEN
                    RAISE EXCEPTION 'A decided request is immutable';
                END IF;
                IF TG_TABLE_NAME = 'account_invitations' AND OLD.state IN ('USED', 'EXPIRED', 'REVOKED') THEN
                    RAISE EXCEPTION 'A terminal invitation cannot be reused';
                END IF;
                IF TG_TABLE_NAME = 'refresh_tokens' AND OLD.state IN ('USED', 'REPLACED', 'EXPIRED', 'REVOKED') THEN
                    RAISE EXCEPTION 'A terminal refresh token cannot become active again';
                END IF;
                IF TG_TABLE_NAME = 'refresh_token_families' AND OLD.state IN ('EXPIRED', 'REVOKED') THEN
                    RAISE EXCEPTION 'A terminal refresh family cannot become active again';
                END IF;
                IF TG_TABLE_NAME IN ('reauth_authorizations', 'operational_authorization_tokens') THEN
                    IF OLD.used_at IS NOT NULL OR OLD.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'A consumed authorization cannot be reused';
                    END IF;
                END IF;
                IF TG_TABLE_NAME = 'mfa_recovery_codes' THEN
                    IF OLD.used_at IS NOT NULL OR OLD.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'A recovery code cannot be reused';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER account_requests_terminal BEFORE UPDATE ON account_requests FOR EACH ROW EXECUTE FUNCTION prevent_terminal_record_reuse();
            CREATE TRIGGER account_invitations_terminal BEFORE UPDATE ON account_invitations FOR EACH ROW EXECUTE FUNCTION prevent_terminal_record_reuse();
            CREATE TRIGGER refresh_tokens_terminal BEFORE UPDATE ON refresh_tokens FOR EACH ROW EXECUTE FUNCTION prevent_terminal_record_reuse();
            CREATE TRIGGER refresh_families_terminal BEFORE UPDATE ON refresh_token_families FOR EACH ROW EXECUTE FUNCTION prevent_terminal_record_reuse();
            CREATE TRIGGER reauth_terminal BEFORE UPDATE ON reauth_authorizations FOR EACH ROW EXECUTE FUNCTION prevent_terminal_record_reuse();
            CREATE TRIGGER operational_authorization_terminal BEFORE UPDATE ON operational_authorization_tokens FOR EACH ROW EXECUTE FUNCTION prevent_terminal_record_reuse();
            CREATE TRIGGER recovery_codes_terminal BEFORE UPDATE ON mfa_recovery_codes FOR EACH ROW EXECUTE FUNCTION prevent_terminal_record_reuse();

            CREATE OR REPLACE FUNCTION immutable_security_history() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION '% is immutable', TG_TABLE_NAME; END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER security_events_no_update BEFORE UPDATE OR DELETE ON security_events FOR EACH ROW EXECUTE FUNCTION immutable_security_history();
            CREATE TRIGGER auth_attempts_no_update BEFORE UPDATE OR DELETE ON auth_attempts FOR EACH ROW EXECUTE FUNCTION immutable_security_history();
            CREATE TRIGGER password_histories_no_update BEFORE UPDATE OR DELETE ON password_histories FOR EACH ROW EXECUTE FUNCTION immutable_security_history();

            CREATE OR REPLACE FUNCTION prevent_security_history_deletion() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION '% cannot be deleted physically', TG_TABLE_NAME; END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER users_no_delete BEFORE DELETE ON users FOR EACH ROW EXECUTE FUNCTION prevent_security_history_deletion();
            CREATE TRIGGER auth_sessions_no_delete BEFORE DELETE ON auth_sessions FOR EACH ROW EXECUTE FUNCTION prevent_security_history_deletion();
            CREATE TRIGGER outbox_events_no_delete BEFORE DELETE ON outbox_events FOR EACH ROW EXECUTE FUNCTION prevent_security_history_deletion();

            CREATE OR REPLACE FUNCTION normalize_user_email() RETURNS trigger AS $$
            BEGIN
                NEW.email := trim(NEW.email);
                NEW.normalized_email := lower(trim(NEW.email));
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER users_normalize_email BEFORE INSERT OR UPDATE OF email ON users FOR EACH ROW EXECUTE FUNCTION normalize_user_email();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS normalize_user_email() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS immutable_security_history() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_security_history_deletion() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_terminal_record_reuse() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_three_active_sessions() CASCADE');
        }

        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('operational_authorization_tokens');
        Schema::dropIfExists('reauth_authorizations');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('auth_attempts');
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('refresh_token_families');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_credentials');
        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('account_invitations');
        Schema::dropIfExists('account_requests');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropUnique(['normalized_email']);
            $table->dropIndex(['state', 'role_id']);
            $table->dropColumn([
                'public_id', 'normalized_email', 'state', 'password_changed_at', 'mfa_enrolled_at',
                'last_login_at', 'security_suspended_at', 'disabled_at',
            ]);
            $table->string('password')->nullable(false)->change();
        });
    }
};
