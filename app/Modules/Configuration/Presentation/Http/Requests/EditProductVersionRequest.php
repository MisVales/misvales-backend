<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;

final class EditProductVersionRequest extends FormRequest
{
    use \App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::PRODUCT_MANAGE)
            && $this->checkCriticalAction($this->user(), CriticalAction::PRODUCT_EDIT);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:100', 'multiple_of:100'],
            'loan_commission_rate' => ['required', 'numeric', 'min:0'],
            'interest_rate_per_fortnight' => ['required', 'numeric', 'min:0'],
            'insurance_amount' => ['required', 'numeric', 'min:0'],
            'fortnight_count' => ['required', 'integer', 'min:1', 'max:100'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
