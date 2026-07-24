<?php

namespace App\Modules\Access\Application\Authorization;

use App\Models\User;

interface PasskeyAssertionValidator
{
    /**
     * @param  array<string, mixed>  $assertion
     */
    public function validate(User $user, array $assertion, string $challenge): bool;
}
