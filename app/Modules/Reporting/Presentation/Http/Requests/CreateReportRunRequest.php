<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Requests;

use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use Illuminate\Foundation\Http\FormRequest;

final class CreateReportRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    public function idempotencyKey(): string
    {
        $value = trim((string) $this->header('Idempotency-Key'));
        if ($value === '' || mb_strlen($value) > 128) {
            throw ReportingException::invalidFilter('Idempotency-Key');
        }

        return $value;
    }
}
