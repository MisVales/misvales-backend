<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PaymentModuleStructureTest extends TestCase
{
    public function test_m11_separates_domain_application_infrastructure_and_presentation(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Payment';
        foreach ([
            'Domain/Services/PaymentAllocator.php',
            'Domain/ValueObjects/Money.php',
            'Domain/Enums/BankImportStatus.php',
            'Application/Contracts/RelationPaymentPort.php',
            'Application/Contracts/PrivateMediaPort.php',
            'Application/Services/ChooseExcessAsCredit.php',
            'Infrastructure/Integrations/UnavailableRelationPaymentPort.php',
            'Infrastructure/Persistence/Eloquent/Models/PaymentAllocationModel.php',
            'Presentation/Http/Controllers/PaymentReadController.php',
            'Presentation/Http/Requests/ReceiveBankImportRequest.php',
            'Presentation/Http/Resources/PaymentAllocationResource.php',
            'Presentation/Http/routes.php',
        ] as $path) {
            self::assertFileExists($root.'/'.$path);
        }
    }
}
