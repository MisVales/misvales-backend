<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
use Illuminate\Foundation\Http\FormRequest;

final class PublishCategoryVersionRequest extends FormRequest
{
    use ChecksPermissions;

    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::CATEGORY_PUBLISH)
            && $this->checkCriticalAction($this->user(), CriticalAction::CATEGORY_VERSION_PUBLISH);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date', 'after_or_equal:now'],
            'reason' => ['required', 'string', 'max:255', 'min:10'],
        ];
    }
}
