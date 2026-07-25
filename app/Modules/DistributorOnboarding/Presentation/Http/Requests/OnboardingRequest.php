<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** Request base que normaliza cabeceras técnicas sin confiar en el cuerpo. */
abstract class OnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function metadata(): OperationMetadata
    {
        $traceId = $this->header('X-Trace-Id');

        return new OperationMetadata(
            idempotencyKey: (string) $this->validated('idempotency_key'),
            requestId: (string) $this->attributes->get('request_id', Str::uuid()->toString()),
            traceId: is_string($traceId) && Str::isUuid($traceId) ? $traceId : null,
            ipAddress: $this->ip(),
            device: $this->userAgent(),
        );
    }

    protected function prepareForValidation(): void
    {
        $ifMatch = $this->header('If-Match');
        $headerVersion = is_string($ifMatch) ? trim($ifMatch, " \t\n\r\0\x0B\"") : null;

        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
            'lock_version' => $this->input('lock_version', ctype_digit((string) $headerVersion) ? (int) $headerVersion : null),
        ]);
    }

    /** @return array<string, list<string>> */
    protected function operationRules(bool $requiresVersion = true): array
    {
        $rules = [
            'idempotency_key' => ['required', 'string', 'max:150'],
        ];

        if ($requiresVersion) {
            $rules['lock_version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
