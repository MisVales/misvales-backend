<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\ExcessBalance\Application\Contracts\RefundExecutionPolicy;

final class FakeRefundExecutionPolicy implements RefundExecutionPolicy
{
    public function validate(string $method, array $fields): void {}
}
