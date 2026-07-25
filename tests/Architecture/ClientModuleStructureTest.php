<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ClientModuleStructureTest extends TestCase
{
    public function test_required_client_module_layers_and_contracts_exist(): void
    {
        $base = dirname(__DIR__, 2).'/app/Modules/Client';

        foreach (['Application', 'Domain', 'Persistence', 'Presentation'] as $layer) {
            self::assertDirectoryExists($base.'/'.$layer);
        }

        foreach ([
            'Application/Contracts/ApplyAuthorizedClientChanges.php',
            'Application/Contracts/ApplyAuthorizedClientAssignment.php',
            'Application/Contracts/ValidateClientPortfolioForTransfer.php',
            'Application/Contracts/RecordClientVoucherReference.php',
            'Application/Contracts/ResolveClientForVoucher.php',
            'Application/Contracts/ResolveClientForCashierVerification.php',
            'Presentation/Http/Resources/ClientListResource.php',
            'Presentation/Http/Resources/ClientAdministrativeDetailResource.php',
            'Presentation/Http/Resources/ClientDistributorDetailResource.php',
            'Presentation/Http/Resources/ClientCashierVerificationResource.php',
            'Presentation/Http/Resources/ClientPortfolioEntryResource.php',
            'Presentation/Http/Resources/ClientBankAccountMaskedResource.php',
        ] as $relativePath) {
            self::assertFileExists($base.'/'.$relativePath);
        }
    }

    public function test_module_does_not_define_client_login_deletion_or_delinquency_routes(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Client/Presentation/Http/routes.php');
        self::assertIsString($routes);
        self::assertStringNotContainsString('login', $routes);
        self::assertStringNotContainsString('delete(', strtolower($routes));
        self::assertStringNotContainsString('delinquen', strtolower($routes));
        self::assertStringNotContainsString('regulariz', strtolower($routes));
    }
}
