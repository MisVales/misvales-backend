<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class VoucherModuleStructureTest extends TestCase
{
    public function test_m09_keeps_domain_application_infrastructure_and_presentation_separate(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Voucher';
        foreach ([
            'Application/Contracts/VoucherRepository.php',
            'Application/Services/CounterVoucherService.php',
            'Application/Services/ModificationWorkflowService.php',
            'Domain/Aggregates/Voucher.php',
            'Domain/Enums/VoucherStatus.php',
            'Domain/Entities/AuthorizationToken.php',
            'Infrastructure/Persistence/Eloquent/Repositories/EloquentVoucherRepository.php',
            'Presentation/Http/Controllers/VoucherController.php',
            'Presentation/Http/Policies/VoucherPolicy.php',
            'Presentation/Http/routes.php',
        ] as $path) {
            self::assertFileExists($root.'/'.$path);
        }
    }
}
