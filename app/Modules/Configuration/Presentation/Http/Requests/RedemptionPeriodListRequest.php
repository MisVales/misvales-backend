<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;

final class RedemptionPeriodListRequest extends FormRequest
{
    use \App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::REDEMPTION_PERIOD_VIEW);
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:DRAFT,PUBLISHED,INACTIVE'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
