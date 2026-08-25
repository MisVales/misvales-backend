<?php

namespace Tests\Unit\Services;

use App\Services\ErrorCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ErrorCatalogServiceTest extends TestCase
{
    public function test_catalog_contains_emitted_api_and_business_error_codes(): void
    {
        Config::set('cache.default', 'array');
        Cache::store('array')->flush();
        $items = app(ErrorCatalogService::class)->all();
        $codes = array_column($items, 'code');

        $this->assertContains('INVALID_CREDENTIALS', $codes);
        $this->assertContains('AUTH_SCOPE_DENIED', $codes);
        $this->assertContains('RESOURCE_VERSION_CONFLICT', $codes);
        $this->assertContains('VOUCHER_STATUS_INVALID', $codes);
        $this->assertContains('CLIENT_VALIDATION_FAILED', $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));

        foreach ($items as $item) {
            $this->assertNotSame('', $item['client_message']);
            $this->assertNotEmpty($item['client_messages']);
            $this->assertArrayHasKey('http_statuses', $item);
            $this->assertArrayNotHasKey('sources', $item);
            $this->assertArrayNotHasKey('internal_definition', $item);
        }
    }
}
