<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Application\Accounts\InvitationIssuer;
use App\Modules\Access\Application\Accounts\InvitationTokenFactory;
use App\Modules\Access\Application\Authentication\CredentialLifecycleService;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConcurrentCredentialLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('d', 32)),
            'access.revocation_cache_store' => 'array',
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 8192,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_two_activation_completions_consume_the_exchange_once(): void
    {
        $user = User::factory()->cashier()->create(['state' => AccountState::PENDING_ACTIVATION, 'password' => null, 'email_verified_at' => null]);
        $invitation = app(InvitationIssuer::class)->issue($user, InvitationPurpose::ACCOUNT_ACTIVATION);
        $plain = app(InvitationTokenFactory::class)->make($invitation->public_id, $user, InvitationPurpose::ACCOUNT_ACTIVATION);
        $exchange = app(CredentialLifecycleService::class)->inspectInvitation($plain)['exchange_token'];
        app(CredentialLifecycleService::class)->completeInvitation($exchange, 'Concurrente Segura 81!', [
            'type' => 'TOTP', 'secret' => self::TOTP_SECRET, 'code' => $this->totpCode(),
        ]);

        $results = $this->runConcurrently(function () use ($exchange): string {
            try {
                app(CredentialLifecycleService::class)->completeInvitation($exchange, null, null, true);

                return 'completed';
            } catch (AccessRuleViolation) {
                return 'rejected';
            }
        });

        $this->assertEqualsCanonicalizing(['completed', 'rejected'], $results);
        $this->assertSame(AccountState::ACTIVE, $user->refresh()->state);
        $this->assertSame(1, AccountInvitation::query()->whereKey($invitation->id)->where('state', TokenState::USED->value)->count());
        $this->assertDatabaseCount('password_histories', 1);
        $this->assertDatabaseCount('mfa_recovery_codes', 10);
    }

    public function test_two_recovery_completions_consume_the_token_once(): void
    {
        $user = User::factory()->administrator()->create(['password' => Hash::make('Anterior Segura 90!'), 'mfa_enrolled_at' => now()]);
        MfaCredential::query()->create([
            'user_id' => $user->id,
            'type' => MfaType::TOTP,
            'credential_identifier' => hash('sha256', self::TOTP_SECRET),
            'encrypted_secret' => self::TOTP_SECRET,
            'state' => 'ACTIVE',
        ]);
        $invitation = app(InvitationIssuer::class)->issue($user, InvitationPurpose::PASSWORD_RECOVERY, 15);
        $plain = app(InvitationTokenFactory::class)->make($invitation->public_id, $user, InvitationPurpose::PASSWORD_RECOVERY);

        $results = $this->runConcurrently(function () use ($plain): string {
            try {
                app(CredentialLifecycleService::class)->completeRecovery(
                    $plain,
                    'Recuperada Concurrente 91!',
                    'TOTP',
                    $this->totpCode(),
                );

                return 'completed';
            } catch (AccessRuleViolation) {
                return 'rejected';
            }
        });

        $this->assertEqualsCanonicalizing(['completed', 'rejected'], $results);
        $this->assertTrue(Hash::check('Recuperada Concurrente 91!', $user->refresh()->password));
        $this->assertSame(1, AccountInvitation::query()->whereKey($invitation->id)->where('state', TokenState::USED->value)->count());
        $this->assertDatabaseCount('password_histories', 2);
    }

    /** @param callable(int): string $operation
     * @return list<string>
     */
    private function runConcurrently(callable $operation): array
    {
        $children = [];
        foreach ([1, 2] as $worker) {
            $resultFile = tempnam(sys_get_temp_dir(), 'misvales-credential-concurrency-');
            [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($parentSocket);
                fread($childSocket, 1);
                DB::disconnect();
                file_put_contents($resultFile, $operation($worker));
                exit(0);
            }
            fclose($childSocket);
            $children[] = compact('pid', 'resultFile', 'parentSocket');
        }
        foreach ($children as $child) {
            fwrite($child['parentSocket'], '1');
            fclose($child['parentSocket']);
        }
        $results = [];
        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $results[] = file_get_contents($child['resultFile']);
            unlink($child['resultFile']);
        }
        DB::disconnect();
        DB::reconnect();

        return $results;
    }

    private function totpCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(self::TOTP_SECRET) as $character) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $secret .= chr(bindec($byte));
            }
        }
        $counter = intdiv(time(), 30);
        $binary = hash_hmac('sha1', pack('N2', 0, $counter), $secret, true);
        $offset = ord($binary[19]) & 0x0F;
        $number = ((ord($binary[$offset]) & 0x7F) << 24)
            | ((ord($binary[$offset + 1]) & 0xFF) << 16)
            | ((ord($binary[$offset + 2]) & 0xFF) << 8)
            | (ord($binary[$offset + 3]) & 0xFF);

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
