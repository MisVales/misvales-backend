<?php

namespace Tests;

use App\Models\AuthSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if (! $app->environment('testing') || $database !== 'misvales_testing') {
            throw new RuntimeException(sprintf(
                'Pruebas bloqueadas: la base de datos debe ser misvales_testing; se recibió %s.',
                $database ?? '(sin configurar)',
            ));
        }

        return $app;
    }

    protected function actingAsApiUser(
        User $user,
        bool $mfaCompleted = true,
        ?CarbonInterface $mfaVerifiedAt = null,
    ): static {
        $token = $user->createToken('test-token');
        AuthSession::query()->create([
            'user_id' => $user->id,
            'session_identifier_hash' => hash('sha256', $token->plainTextToken),
            'device_id' => 'test-device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addHour(),
            'mfa_verified_at' => $mfaCompleted ? ($mfaVerifiedAt ?? now()) : null,
            'last_activity_at' => now(),
        ]);
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }
}
