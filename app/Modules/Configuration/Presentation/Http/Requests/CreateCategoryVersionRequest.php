<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;

final class CreateCategoryVersionRequest extends FormRequest
{
    use \App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::CATEGORY_MANAGE)
            && $this->checkCriticalAction($this->user(), CriticalAction::CATEGORY_EDIT);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'distributor_profit_rate' => ['required', 'numeric', 'min:0'],
        ];
    }
}
