<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;

final class DeactivateCategoryRequest extends FormRequest
{
    use \App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::CATEGORY_MANAGE)
            && $this->checkCriticalAction($this->user(), CriticalAction::CATEGORY_DEACTIVATE);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255', 'min:10'],
        ];
    }
}
