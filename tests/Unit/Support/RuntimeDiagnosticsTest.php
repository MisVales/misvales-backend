<?php

namespace Tests\Unit\Support;

use App\Support\RuntimeDiagnostics;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeDiagnosticsTest extends TestCase
{
    public function test_sensitive_request_values_are_redacted(): void
    {
        $request = Request::create('/api/v1/auth/invitations/inspect?token=secret', 'POST', [
            'email' => 'person@example.test',
            'password' => 'super-secret',
            'nested' => ['access_token' => 'reusable-token'],
        ]);

        $diagnostics = RuntimeDiagnostics::request($request);

        self::assertSame('person@example.test', $diagnostics['input']['email']);
        self::assertArrayNotHasKey('password', $diagnostics['input']);
        self::assertSame('[REDACTED]', $diagnostics['input']['nested']['access_token']);
        self::assertSame(['token'], $diagnostics['query_keys']);
    }

    public function test_exception_details_keep_location_but_redact_url_tokens(): void
    {
        $details = RuntimeDiagnostics::exception(
            new RuntimeException('Falló https://safeacces.lat/activar-cuenta?token=secret-value')
        );

        self::assertSame(RuntimeException::class, $details['class']);
        self::assertStringContainsString('token=[REDACTED]', $details['message']);
        self::assertArrayHasKey('file', $details);
        self::assertArrayHasKey('line', $details);
    }
}
