<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\ConfigurationDefinition;
use App\Enums\VersionStatus;

class Module3ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_optimistic_locking_prevents_overwrite()
    {
        $def = ConfigurationDefinition::create(['key' => 'TEST_CONC', 'name' => 'A', 'value_type' => 'STRING', 'status' => 'ACTIVE', 'created_by' => \Illuminate\Support\Str::uuid()]);
        $version = $def->versions()->create([
            'version' => 1,
            'value' => 'Val',
            'status' => VersionStatus::DRAFT,
            'effective_from' => now()->addDay(),
            'lock_version' => 1
        ]);
        
        $v1 = $def->versions()->first();
        $v1->value = 'Val2';
        $v1->save(); // increments lock_version to 2
        
        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionCode(409); // RESOURCE_VERSION_CONFLICT
        
        $version->value = 'Val3'; // holds lock_version 1
        $version->save(); // will fail
    }
}
