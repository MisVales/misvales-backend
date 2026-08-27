<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;

final class RuntimeDiagnostics
{
    private const SENSITIVE_KEYS = [
        'authorization', 'cookie', 'password', 'password_confirmation', 'token',
        'exchange_token', 'access_token', 'refresh_token', 'totp_code',
        'recovery_code', 'secret', 'clientdatajson', 'attestationobject', 'signature',
    ];

    public static function request(Request $request): array
    {
        return [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'route' => $request->route()?->getName(),
            'request_id' => $request->attributes->get('request_id'),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'trace_id' => $request->attributes->get('trace_id'),
            'actor_id' => $request->user()?->id,
            'input' => self::sanitize($request->except(self::SENSITIVE_KEYS)),
            'query_keys' => array_keys($request->query()),
            'content_type' => $request->header('Content-Type'),
            'accept' => $request->header('Accept'),
        ];
    }

    public static function exception(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => self::sanitizeString($exception->getMessage()),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_slice(array_map(
                fn (array $frame): array => array_filter([
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'class' => $frame['class'] ?? null,
                    'function' => $frame['function'] ?? null,
                ], fn ($value): bool => $value !== null),
                $exception->getTrace()
            ), 0, 12),
        ];
    }

    public static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitive($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $itemKey => $item) {
                $sanitized[$itemKey] = self::sanitize($item, is_string($itemKey) ? $itemKey : null);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return self::sanitize((array) $value);
        }

        return is_string($value) ? self::sanitizeString($value) : $value;
    }

    private static function isSensitive(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', $key));

        return collect(self::SENSITIVE_KEYS)->contains(
            fn (string $sensitive): bool => $normalized === $sensitive || str_ends_with($normalized, '_'.$sensitive)
        );
    }

    private static function sanitizeString(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/([?&](?:token|code|secret|key)=)[^&\s]+/i', '$1[REDACTED]', $value) ?? $value;

        return mb_substr($value, 0, 4000);
    }
}
