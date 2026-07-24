<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Access\Application\Auth\SessionManager;
use App\Modules\Access\Application\Security\DeterministicRiskEngine;
use App\Modules\Access\Application\Security\OutboxDispatcher;
use App\Modules\Access\Application\Security\OutboxProcessor;
use App\Modules\Access\Application\Security\RiskCoordinator;
use App\Modules\Access\Application\Security\SecretSanitizer;
use App\Modules\Access\Application\Security\SecurityAlertService;
use App\Modules\Access\Application\Security\SecurityAuditService;
use App\Modules\Access\Application\Security\SecurityNotificationSender;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\Security\RiskLevel;
use App\Modules\Access\Domain\Security\RiskResponse;
use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\NotificationDelivery;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshTokenFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class B11SecurityObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_risk_engine_is_deterministic_and_location_never_authenticates_by_itself(): void
    {
        $engine = $this->app->make(DeterministicRiskEngine::class);

        $newLocation = $engine->assess('LOGIN_CONTEXT_EVALUATED', ['new_location' => true]);
        $this->assertSame(RiskLevel::MEDIUM, $newLocation->level);
        $this->assertSame(RiskResponse::REQUIRE_MFA, $newLocation->response);
        $this->assertContains('NEW_COARSE_LOCATION', $newLocation->matchedRules);

        $mobileIpChange = $engine->assess('SESSION_NETWORK_CHANGED', [
            'network_changed' => true,
            'mobile_network' => true,
        ]);
        $this->assertSame(RiskLevel::LOW, $mobileIpChange->level);
        $this->assertSame(RiskResponse::CONTINUE_AND_RECORD, $mobileIpChange->response);

        $impossibleTravel = $engine->assess('LOGIN_CONTEXT_EVALUATED', ['impossible_travel' => true]);
        $this->assertSame(RiskLevel::HIGH, $impossibleTravel->level);
        $this->assertSame(RiskResponse::REJECT_AND_REVOKE_SESSION, $impossibleTravel->response);
    }

    public function test_medium_risk_invalidates_temporary_authorization_and_requires_mfa(): void
    {
        [$user, $session] = $this->userAndSession();
        ReauthAuthorization::query()->create([
            'user_id' => $user->id,
            'auth_session_id' => $session->id,
            'requester_user_id' => $user->id,
            'method' => 'PASSKEY',
            'action' => 'password.change',
            'record_id' => $user->public_id,
            'parameters_hash' => hash('sha256', '[]'),
            'context_version' => $user->context_version,
            'token_hash' => hash('sha256', 'plain'),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);

        $assessment = $this->app->make(RiskCoordinator::class)->assessAndRespond(
            'LOGIN_CONTEXT_EVALUATED',
            $user,
            $session,
            ['new_location' => true],
        );

        $this->assertSame(RiskResponse::REQUIRE_MFA, $assessment->response);
        $this->assertNotNull(ReauthAuthorization::query()->sole()->revoked_at);
        $this->assertSame('ACTIVE', $session->refresh()->state);
    }

    public function test_refresh_token_reuse_creates_critical_incident_alert_and_revokes_all_sessions(): void
    {
        [$user, $compromisedSession] = $this->userAndSession();
        [, $otherSession] = $this->userAndSession($user);
        $this->attachAccessToken($user, $compromisedSession);
        $this->attachAccessToken($user, $otherSession);
        $plainRefresh = 'refresh-token-that-was-already-rotated';
        $family = RefreshTokenFamily::query()->create([
            'auth_session_id' => $compromisedSession->id,
            'application' => 'administrativa',
            'state' => SessionState::ACTIVE,
            'absolute_expires_at' => now()->addHour(),
        ]);
        RefreshToken::query()->create([
            'refresh_token_family_id' => $family->id,
            'auth_session_id' => $compromisedSession->id,
            'token_hash' => hash('sha256', $plainRefresh),
            'state' => TokenState::REPLACED,
            'issued_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'used_at' => now()->subSecond(),
            'replaced_at' => now()->subSecond(),
        ]);

        try {
            $this->app->make(SessionManager::class)->refreshSession(
                $plainRefresh,
                'administrativa',
                '127.0.0.2',
            );
            $this->fail('Refresh token reuse was accepted.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(401, $exception->getResponse()->getStatusCode());
        }

        $this->assertSame(2, AuthSession::query()->where('state', 'REVOKED')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('security_events', [
            'event_type' => 'REFRESH_TOKEN_REUSE_DETECTED',
            'risk_level' => 'CRITICAL',
            'result' => 'REVOKE_ALL_AND_OPEN_INCIDENT',
        ]);
        $this->assertDatabaseHas('security_alerts', [
            'affected_user_id' => $user->id,
            'severity' => 'CRITICAL',
            'state' => 'OPEN',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'type' => 'SECURITY_ALERT',
            'result' => 'DELIVERED',
        ]);
    }

    public function test_branch_manager_cannot_see_other_branch_and_admin_cannot_act(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $affectedA = User::factory()->distributor()->create(['branch_id' => $branchA->id]);
        $affectedB = User::factory()->distributor()->create(['branch_id' => $branchB->id]);
        $audit = $this->app->make(SecurityAuditService::class);
        $alerts = $this->app->make(SecurityAlertService::class);
        $alertA = $alerts->open(
            $audit->record('SECURITY_TEST_A', 'DENIED', $affectedA, $affectedA, ['branch_id' => $branchA->id]),
            $affectedA,
            $branchA->id,
            'HIGH',
            'SECURITY_TEST_A',
            'Alerta sucursal A',
        );
        $alerts->open(
            $audit->record('SECURITY_TEST_B', 'DENIED', $affectedB, $affectedB, ['branch_id' => $branchB->id]),
            $affectedB,
            $branchB->id,
            'HIGH',
            'SECURITY_TEST_B',
            'Alerta sucursal B',
        );
        $manager = User::factory()->sucursalManager()->create(['branch_id' => $branchA->id]);
        $this->actingAs($manager, 'sanctum');

        $this->getJson('/api/v1/security/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.public_id', $alertA->public_id);

        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/v1/security/alerts')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
        $this->postJson('/api/v1/security/alerts/'.$alertA->public_id.'/acknowledge')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ALERT_ACTION_FORBIDDEN');
        $this->assertSame('OPEN', $alertA->refresh()->state);
    }

    public function test_outbox_retry_is_idempotent_and_does_not_duplicate_notification(): void
    {
        Queue::fake();
        $event = $this->app->make(OutboxDispatcher::class)->record(
            'SECURITY_ALERT',
            'security-alert:one',
            ['official_url' => 'http://localhost:4200/seguridad'],
            'affected@example.com',
            'security-alert',
        );
        $sender = new class implements SecurityNotificationSender
        {
            public int $calls = 0;

            public function send(string $recipient, string $template, array $payload, string $idempotencyKey): string
            {
                $this->calls++;

                return 'provider-'.$idempotencyKey;
            }
        };
        $processor = new OutboxProcessor($sender, $this->app->make(SecretSanitizer::class));

        $processor->process($event->id);
        $processor->process($event->id);

        $this->assertSame(1, $sender->calls);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertSame(1, NotificationDelivery::query()->sole()->attempts);
        $this->assertSame('SENT', $event->refresh()->state);
    }

    public function test_audit_and_outbox_automatically_remove_secrets(): void
    {
        $user = User::factory()->generalManager()->create();
        $rawPassword = 'Never-store-this-password!';
        $rawToken = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.invalid-signature';
        $event = $this->app->make(SecurityAuditService::class)->record(
            'SECRET_SCAN_TEST',
            'DENIED',
            $user,
            $user,
            [
                'resource_type' => 'users',
                'resource_id' => $user->public_id,
                'before' => ['password' => $rawPassword, 'state' => 'ACTIVE'],
                'after' => ['access_token' => $rawToken, 'state' => 'ACTIVE'],
                'metadata' => [
                    'authorization' => 'Bearer '.$rawToken,
                    'safe_reason' => 'Prueba automatizada',
                ],
            ],
        );
        $outbox = $this->app->make(OutboxDispatcher::class)->record(
            'SECRET_SCAN_TEST',
            'secret-scan:one',
            [
                'refresh_token' => $rawToken,
                'password' => $rawPassword,
                'safe_reason' => 'Prueba automatizada',
            ],
        );
        $serializedPersistence = $event->fresh()->toJson().$outbox->fresh()->toJson();

        $this->assertStringNotContainsString($rawPassword, $serializedPersistence);
        $this->assertStringNotContainsString($rawToken, $serializedPersistence);
        $this->assertStringNotContainsString('Bearer ', $serializedPersistence);
        $this->assertSame('America/Monterrey', $event->display_timezone);
        $this->assertSame($user->id, $event->requester_user_id);
    }

    /**
     * @return array{User, AuthSession}
     */
    private function userAndSession(?User $user = null): array
    {
        $user ??= User::factory()->generalManager()->create([
            'state' => 'ACTIVE',
            'context_version' => 1,
        ]);
        $session = AuthSession::query()->create([
            'user_id' => $user->id,
            'application' => 'administrativa',
            'device_id' => (string) Str::uuid(),
            'ip_address' => '127.0.0.1',
            'context_version' => $user->context_version,
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(8),
            'state' => 'ACTIVE',
        ]);

        return [$user, $session];
    }

    private function attachAccessToken(User $user, AuthSession $session): PersonalAccessToken
    {
        $token = $user->createToken('administrativa', ['*'], now()->addMinutes(10))->accessToken;
        $token->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => $user->context_version,
        ])->save();

        return $token;
    }
}
