<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
use Illuminate\Foundation\Http\FormRequest;

final class CreateProductVersionRequest extends FormRequest
{
    use ChecksPermissions;

    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::PRODUCT_MANAGE)
            && $this->checkCriticalAction($this->user(), CriticalAction::PRODUCT_VERSION_EDIT);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:100'],
            'loan_commission_rate' => ['required', 'numeric', 'min:0'],
            'interest_rate_per_fortnight' => ['required', 'numeric', 'min:0'],
            'insurance_amount' => ['required', 'numeric', 'min:0'],
            'fortnight_count' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
