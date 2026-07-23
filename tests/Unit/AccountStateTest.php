<?php

namespace Tests\Unit;

use App\Modules\Access\Domain\Accounts\AccountState;
use PHPUnit\Framework\TestCase;

class AccountStateTest extends TestCase
{
    public function test_only_active_accounts_can_authenticate(): void
    {
        foreach (AccountState::cases() as $state) {
            $this->assertSame($state === AccountState::ACTIVE, $state->canAuthenticate());
        }
    }
}
