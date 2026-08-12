<?php

namespace App\Services\Observabilidad;

final class SanitizadorDatos
{
    private const SENSITIVE = ['password', 'token', 'cookie', 'curp', 'rfc', 'clabe', 'account', 'secret', 'document', 'file', 'credential', 'authorization'];

    public function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            $clean[$key] = collect(self::SENSITIVE)->contains(fn (string $term): bool => str_contains($normalized, $term)) ? '[REDACTED]' : $this->sanitize($item);
        }

        return $clean;
    }
}
