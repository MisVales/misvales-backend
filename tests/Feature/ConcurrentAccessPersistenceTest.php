<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Application\Authentication\ConsumeInvitation;
use App\Modules\Access\Application\Authentication\RotateRefreshToken;
use App\Modules\Access\Domain\Authentication\TokenNotActive;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshTokenFamily;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentAccessPersistenceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_two_concurrent_consumers_only_use_an_invitation_once(): void
    {
        $plainToken = 'concurrent-invitation';
        AccountInvitation::query()->create([
            'user_id' => User::factory()->generalManager()->create()->id,
            'purpose' => 'ACCOUNT_ACTIVATION',
            'token_hash' => hash('sha256', $plainToken),
            'state' => TokenState::ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $results = $this->runConcurrently(function () use ($plainToken): string {
            try {
                app(ConsumeInvitation::class)->execute($plainToken);

                return 'consumed';
            } catch (TokenNotActive) {
                return 'rejected';
            }
        });

        $this->assertEqualsCanonicalizing(['consumed', 'rejected'], $results);
        $this->assertSame(1, AccountInvitation::query()->where('state', TokenState::USED)->count());
    }

    public function test_two_concurrent_rotations_leave_one_active_successor(): void
    {
        $user = User::factory()->generalManager()->create();
        $session = AuthSession::query()->create([
            'user_id' => $user->id,
            'application' => 'INTERNAL_WEB',
            'device_id' => 'concurrent-device',
            'last_activity_at' => now(),
            'expires_at' => now()->addHour(),
            'state' => SessionState::ACTIVE,
            'context_version' => $user->context_version,
        ]);
        $family = RefreshTokenFamily::query()->create([
            'auth_session_id' => $session->id,
            'application' => 'INTERNAL_WEB',
            'state' => SessionState::ACTIVE,
            'absolute_expires_at' => now()->addDay(),
        ]);
        RefreshToken::query()->create([
            'refresh_token_family_id' => $family->id,
            'auth_session_id' => $session->id,
            'token_hash' => hash('sha256', 'concurrent-current'),
            'state' => TokenState::ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $results = $this->runConcurrently(function (int $worker): string {
            try {
                app(RotateRefreshToken::class)->execute(
                    'concurrent-current',
                    "concurrent-successor-{$worker}",
                    now()->toImmutable()->addHour(),
                );

                return 'rotated';
            } catch (TokenNotActive) {
                return 'rejected';
            }
        });

        $this->assertEqualsCanonicalizing(['rotated', 'rejected'], $results);
        $this->assertSame(1, RefreshToken::query()->where('refresh_token_family_id', $family->id)->where('state', TokenState::ACTIVE)->count());
    }

    /**
     * @param  callable(int): string  $operation
     * @return list<string>
     */
    private function runConcurrently(callable $operation): array
    {
        $children = [];
        foreach ([1, 2] as $worker) {
            $resultFile = tempnam(sys_get_temp_dir(), 'misvales-concurrency-');
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
}
