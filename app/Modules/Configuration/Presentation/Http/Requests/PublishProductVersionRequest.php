<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;

final class PublishProductVersionRequest extends FormRequest
{
    use \App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::PRODUCT_PUBLISH)
            && $this->checkCriticalAction($this->user(), CriticalAction::PRODUCT_VERSION_PUBLISH);
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date', 'after_or_equal:now'],
            'reason' => ['required', 'string', 'max:255', 'min:10'],
        ];
    }
}
