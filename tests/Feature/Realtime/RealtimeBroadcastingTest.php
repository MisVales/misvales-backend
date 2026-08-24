<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TraceRequest;
use App\Models\User;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Foundation\Testing\TestCase as FrameworkTestCase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

final class RealtimeBroadcastingTest extends FrameworkTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(RequireMfaCompleted::class);
        $this->withoutMiddleware(TraceRequest::class);
        $this->mock(SecurityAuditService::class)
            ->shouldReceive('log')
            ->zeroOrMoreTimes();
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'test-public-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        config()->set('queue.default', 'redis');
        Broadcast::setDefaultDriver('reverb');

        Broadcast::channel(
            'testing-private.{userId}',
            fn (User $user, string $userId): bool => hash_equals(
                (string) $user->getAuthIdentifier(),
                $userId,
            ),
            ['guards' => ['sanctum']],
        );

        Broadcast::channel(
            'testing-presence.{roomId}',
            fn (User $user, string $roomId): array => [
                'id' => (string) $user->getAuthIdentifier(),
                'name' => $user->name,
            ],
            ['guards' => ['sanctum']],
        );
    }

    public function test_private_channel_authorization_uses_the_current_sanctum_user(): void
    {
        $user = $this->user('ACTIVE');
        $other = $this->user('ACTIVE');
        Sanctum::actingAs($user);

        $this->authorizeChannel('private-testing-private.'.$user->id)
            ->assertSuccessful()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel('private-testing-private.'.$other->id)->assertForbidden();
    }

    public function test_presence_channel_authorization_returns_member_data(): void
    {
        $user = $this->user('ACTIVE');
        Sanctum::actingAs($user);

        $this->authorizeChannel('presence-testing-presence.infrastructure')
            ->assertSuccessful()
            ->assertJsonStructure(['auth', 'channel_data']);
    }

    public function test_unauthenticated_or_disabled_user_cannot_authorize_channels(): void
    {
        $user = $this->user('ACTIVE');
        $this->authorizeChannel('private-testing-private.'.$user->id)->assertUnauthorized();

        $disabled = $this->user('DISABLED');
        Sanctum::actingAs($disabled);
        $this->authorizeChannel('private-testing-private.'.$disabled->id)->assertUnauthorized();
    }

    public function test_broadcast_authorization_is_rate_limited_per_user(): void
    {
        $user = $this->user('ACTIVE');
        Sanctum::actingAs($user);
        RateLimiter::clear((string) $user->id);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->authorizeChannel(
                'private-testing-private.'.$user->id,
                "1.{$attempt}",
            )->assertSuccessful();
        }

        $this->authorizeChannel(
            'private-testing-private.'.$user->id,
            '1.31',
        )->assertTooManyRequests();
    }

    public function test_channel_authorization_preflight_allows_the_configured_frontend(): void
    {
        config()->set('cors.allowed_origins', ['https://app.example.test']);

        $this->withHeaders([
            'Origin' => 'https://app.example.test',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,x-socket-id',
        ])->options('/api/broadcasting/auth')
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_broadcasting_and_horizon_keep_broadcasts_separate_from_default_jobs(): void
    {
        self::assertSame('reverb', config('broadcasting.default'));
        self::assertSame('broadcasts', config('broadcasting.queue'));
        self::assertSame('redis', config('queue.default'));
        self::assertSame(['default'], config('horizon.defaults.supervisor-default.queue'));
        self::assertSame(
            ['broadcasts'],
            config('horizon.defaults.supervisor-broadcasts.queue'),
        );
    }

    private function authorizeChannel(string $channelName, string $socketId = '1.1')
    {
        return $this->postJson('/api/broadcasting/auth', [
            'socket_id' => $socketId,
            'channel_name' => $channelName,
        ]);
    }

    private function user(string $state): User
    {
        return (new User)->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'Realtime Test User',
            'email' => Str::uuid().'@example.test',
            'state' => $state,
        ]);
    }
}
