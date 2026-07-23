<?php

use App\Modules\Access\Domain\Authentication\TokenState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 64);
            $table->string('state', 32)->default(TokenState::ACTIVE->value);
            $table->string('token_hash', 64)->unique();
            $table->string('email_hash', 64);
            $table->unsignedInteger('credential_version');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'state']);
        });

        Schema::create('invitation_exchanges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_invitation_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('password_hash');
            $table->timestamp('recorded_at')->index();
            $table->index(['user_id', 'recorded_at']);
        });

        Schema::create('mfa_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('credential_identifier', 255);
            $table->text('public_key')->nullable();
            $table->text('encrypted_secret')->nullable();
            $table->unsignedBigInteger('signature_counter')->default(0);
            $table->json('metadata')->nullable();
            $table->string('state', 32)->default('ACTIVE');
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'type', 'credential_identifier']);
            $table->index(['user_id', 'type', 'state']);
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('code_hash', 64);
            $table->timestamp('generated_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'code_hash']);
            $table->index(['user_id', 'used_at', 'revoked_at']);
        });

        Schema::create('reauth_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 128);
            $table->string('record_id', 128)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'action', 'record_id']);
        });

        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('rule', 128);
            $table->string('result', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['rule', 'created_at']);
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 128);
            $table->json('payload');
            $table->string('deduplication_key')->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('reauth_authorizations');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_credentials');
        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('invitation_exchanges');
        Schema::dropIfExists('account_invitations');
    }
};
