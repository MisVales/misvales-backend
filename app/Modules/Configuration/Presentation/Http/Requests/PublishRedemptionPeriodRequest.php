<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Configuration\Presentation\Http\Concerns\ChecksPermissions;
use Illuminate\Foundation\Http\FormRequest;

final class PublishRedemptionPeriodRequest extends FormRequest
{
    use ChecksPermissions;

    public function authorize(): bool
    {
        return $this->checkPermission($this->user(), PermissionCode::REDEMPTION_PERIOD_MANAGE)
            && $this->checkCriticalAction($this->user(), CriticalAction::REDEMPTION_PERIOD_PUBLISH);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255', 'min:10'],
        ];
    }
}
