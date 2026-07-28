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

    public function test_m08_generation_keeps_financial_rules_out_of_http_layer(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Voucher';
        foreach ([
            'Application/Commands/GenerateVoucher/Handler.php',
            'Application/Contracts/VoucherGenerationRepository.php',
            'Domain/Enums/VoucherType.php',
            'Domain/Services/VoucherTypeResolver.php',
            'Domain/Services/VoucherCalculator.php',
            'Domain/Services/InstallmentAllocator.php',
            'Domain/ValueObjects/Money.php',
            'Infrastructure/Persistence/Eloquent/Repositories/EloquentVoucherGenerationRepository.php',
            'Presentation/Http/Requests/GenerateVoucherRequest.php',
        ] as $path) {
            self::assertFileExists($root.'/'.$path);
        }

        $controller = file_get_contents($root.'/Presentation/Http/Controllers/VoucherController.php');
        self::assertIsString($controller);
        self::assertStringNotContainsString('bcadd(', $controller);
        self::assertStringNotContainsString('bcmul(', $controller);
    }
}
