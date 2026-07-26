<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
use Illuminate\Foundation\Http\FormRequest;

final class EditConfigurationVersionRequest extends FormRequest
{
    use ChecksPermissions;

    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::CONFIGURATION_MANAGE)
            && $this->checkCriticalAction($this->user(), CriticalAction::CONFIGURATION_VERSION_EDIT);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'value' => ['required', 'string'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
