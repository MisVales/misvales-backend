<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Middleware;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\SecurityEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Registra rechazos funcionales sin copiar cuerpo, identificadores sensibles ni excepciones. */
final class AuditOnboardingFailure
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $errorCode = $this->errorCode($response);
        if ($errorCode !== null) {
            $this->record($request, $errorCode);
        }

        return $response;
    }

    private function record(Request $request, string $errorCode): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return;
        }

        try {
            $event = new SecurityEvent;
            $event->forceFill([
                'actor_user_id' => $user->id,
                'target_user_id' => null,
                'auth_session_id' => null,
                'rule_code' => $errorCode,
                'scope' => 'M04',
                'result' => 'DENIED',
                'correlation_id' => $this->correlationId($request),
                'metadata' => [
                    'method' => $request->method(),
                    'route_name' => $request->route()?->getName(),
                ],
                'occurred_at' => now(),
            ])->save();
        } catch (Throwable $auditFailure) {
            Log::error('M04 security-event persistence failed.', [
                'exception_class' => $auditFailure::class,
            ]);
        }
    }

    private function correlationId(Request $request): string
    {
        $requestId = $request->attributes->get('request_id');

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }

    private function errorCode(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return null;
        }
        $payload = json_decode($content, true);
        $code = is_array($payload) ? ($payload['error']['code'] ?? null) : null;

        return is_string($code) && $code !== '' ? mb_substr($code, 0, 100) : null;
    }
}
