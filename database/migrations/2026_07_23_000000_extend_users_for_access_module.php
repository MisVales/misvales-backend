<?php

use App\Modules\Access\Domain\Accounts\AccountState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->after('id')->unique();
            $table->string('normalized_email')->nullable()->after('email')->unique();
            $table->string('state')->default(AccountState::PENDING_ACTIVATION->value)->after('password');
            $table->unsignedInteger('credential_version')->default(1)->after('state');
            $table->unsignedInteger('context_version')->default(1)->after('credential_version');
            $table->timestamp('password_changed_at')->nullable()->after('email_verified_at');
            $table->timestamp('mfa_enrolled_at')->nullable()->after('password_changed_at');
            $table->timestamp('activated_at')->nullable()->after('mfa_enrolled_at');
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'public_id' => (string) Str::uuid(),
                    'normalized_email' => mb_strtolower((string) $user->email),
                ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->change();
            $table->string('normalized_email')->nullable(false)->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'public_id',
                'normalized_email',
                'state',
                'credential_version',
                'context_version',
                'password_changed_at',
                'mfa_enrolled_at',
                'activated_at',
            ]);
            $table->string('password')->nullable(false)->change();
        });
    }
};
