<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reauth_authorizations', function (Blueprint $table): void {
            $table->foreignId('requester_user_id')->nullable()->after('auth_session_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 32)->after('requester_user_id');
            $table->string('resource_type', 128)->nullable()->after('action');
            $table->string('parameters_hash', 64)->after('branch_id');
            $table->unsignedInteger('context_version')->after('parameters_hash');
            $table->text('reason')->nullable()->after('context_version');
            $table->string('revoked_reason', 128)->nullable()->after('revoked_at');
            $table->index(['auth_session_id', 'used_at', 'revoked_at'], 'reauth_session_state_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reauth_authorizations', function (Blueprint $table): void {
            $table->dropIndex('reauth_session_state_idx');
            $table->dropConstrainedForeignId('requester_user_id');
            $table->dropColumn([
                'method',
                'resource_type',
                'parameters_hash',
                'context_version',
                'reason',
                'revoked_reason',
            ]);
        });
    }
};
