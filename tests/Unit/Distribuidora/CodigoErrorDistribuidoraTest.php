<?php

namespace Tests\Unit\Distribuidora;

use App\Enums\CodigoErrorDistribuidora;
use PHPUnit\Framework\TestCase;

class CodigoErrorDistribuidoraTest extends TestCase
{
    public function test_contiene_todos_los_codigos_minimos_del_documento(): void
    {
        self::assertSame([
            'DISTRIBUTOR_APPLICATION_NOT_APPROVED',
            'DISTRIBUTOR_ALREADY_EXISTS',
            'DISTRIBUTOR_NUMBER_CONFLICT',
            'DISTRIBUTOR_CATEGORY_NOT_PUBLISHED',
            'DISTRIBUTOR_CATEGORY_NOT_EFFECTIVE',
            'DISTRIBUTOR_COORDINATOR_SCOPE_INVALID',
            'DISTRIBUTOR_BRANCH_MISMATCH',
            'DISTRIBUTOR_USER_CONFLICT',
            'DISTRIBUTOR_INITIAL_CREDIT_INVALID',
            'DISTRIBUTOR_ACTIVATION_STATE_INVALID',
            'DISTRIBUTOR_INVITATION_RATE_LIMITED',
            'RESOURCE_VERSION_CONFLICT',
            'AUTH_SCOPE_DENIED',
        ], array_column(CodigoErrorDistribuidora::cases(), 'value'));
    }
}
