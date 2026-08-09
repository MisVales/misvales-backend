<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Exceptions\BusinessException;
use App\Models\ConfigurationDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Module3ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_optimistic_locking_prevents_overwrite()
    {
        $user = User::factory()->create();
        $def = ConfigurationDefinition::create(['key' => 'TEST_CONC', 'name' => 'A', 'value_type' => 'STRING', 'status' => 'ACTIVE', 'created_by' => $user->id]);
        $version = $def->versions()->create([
            'version' => 1,
            'value' => 'Val',
            'status' => VersionStatus::DRAFT,
            'effective_from' => now()->addDay(),
            'lock_version' => 1,
            'created_by' => $user->id,
            'reason' => 'Initial draft',
        ]);

        $v1 = $def->versions()->first();
        $v1->value = 'Val2';
        $v1->save(); // increments lock_version to 2

        $this->expectException(BusinessException::class);
        $this->expectExceptionCode(409); // RESOURCE_VERSION_CONFLICT

        $version->value = 'Val3'; // holds lock_version 1
        $version->save(); // will fail
    }
}
