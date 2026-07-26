<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Requests;

final class ChooseCreditBalanceRequest extends ExcessMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->baseRules();
    }
}
