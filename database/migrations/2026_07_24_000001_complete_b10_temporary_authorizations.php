<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reauth_authorizations', function (Blueprint $table): void {
            $table->foreignId('auth_session_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_user_id')->nullable()->after('auth_session_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 32)->after('requester_user_id');
            $table->string('resource_type', 128)->nullable()->after('action');
            $table->uuid('branch_id')->nullable()->after('record_id');
            $table->string('parameters_hash', 64)->after('branch_id');
            $table->unsignedInteger('context_version')->after('parameters_hash');
            $table->text('reason')->nullable()->after('context_version');
            $table->timestamp('issued_at')->after('token_hash');
            $table->string('revoked_reason', 128)->nullable()->after('revoked_at');
            $table->index(['auth_session_id', 'used_at', 'revoked_at'], 'reauth_session_state_idx');
        });

        Schema::create('operational_authorization_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorizer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('executor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorizer_session_id')->constrained('auth_sessions')->cascadeOnDelete();
            $table->string('action', 128);
            $table->string('resource_type', 128);
            $table->string('resource_id', 128);
            $table->uuid('branch_id')->nullable();
            $table->string('parameters_hash', 64);
            $table->text('reason');
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('context_version');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 128)->nullable();
            $table->timestamps();
            $table->index(
                ['executor_user_id', 'action', 'resource_type', 'resource_id'],
                'operational_executor_binding_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_authorization_tokens');

        Schema::table('reauth_authorizations', function (Blueprint $table): void {
            $table->dropIndex('reauth_session_state_idx');
            $table->dropConstrainedForeignId('auth_session_id');
            $table->dropConstrainedForeignId('requester_user_id');
            $table->dropColumn([
                'method',
                'resource_type',
                'branch_id',
                'parameters_hash',
                'context_version',
                'reason',
                'issued_at',
                'revoked_reason',
            ]);
        });
    }
};
