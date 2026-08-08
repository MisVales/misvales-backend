<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\ConfigurationDefinition;
use App\Enums\VersionStatus;
use App\Models\AuditLog;

class Module3HistoricalTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_audit_log_isolation()
    {
        $def = ConfigurationDefinition::create(['key' => 'TEST_HIST', 'name' => 'A', 'value_type' => 'STRING', 'status' => 'ACTIVE', 'created_by' => \Illuminate\Support\Str::uuid()]);
        $version = $def->versions()->create([
            'version' => 1,
            'value' => 'Val',
            'status' => VersionStatus::DRAFT,
            'effective_from' => now()->addDay()
        ]);
        
        // Assert version was created
        $this->assertDatabaseHas('configuration_versions', ['id' => $version->id]);
        // Audits handled by observer
        $this->assertDatabaseHas('audit_logs', ['event_name' => 'ConfigurationVersionCreated']);
    }
}
