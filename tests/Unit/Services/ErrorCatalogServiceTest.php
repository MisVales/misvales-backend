<?php

namespace Tests\Unit\Services;

use App\Services\ErrorCatalogService;
use Tests\TestCase;

class ErrorCatalogServiceTest extends TestCase
{
    public function test_catalog_contains_emitted_api_and_business_error_codes(): void
    {
        $items = app(ErrorCatalogService::class)->all();
        $codes = array_column($items, 'code');

        $this->assertContains('INVALID_CREDENTIALS', $codes);
        $this->assertContains('AUTH_SCOPE_DENIED', $codes);
        $this->assertContains('RESOURCE_VERSION_CONFLICT', $codes);
        $this->assertContains('VOUCHER_STATUS_INVALID', $codes);
        $this->assertContains('CLIENT_VALIDATION_FAILED', $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));

        foreach ($items as $item) {
            $this->assertNotSame('', $item['client_definition']);
            $this->assertNotSame('', $item['internal_definition']);
            $this->assertNotSame('', $item['admin_definition']);
            $this->assertNotEmpty($item['sources']);
        }
    }
}
