<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Registro de auditoría del módulo de configuraciones.
 *
 * @property int                     $id
 * @property string                  $public_id
 * @property string                  $event_type
 * @property string                  $result
 * @property int|null                $actor_user_id
 * @property int|null                $executor_user_id
 * @property string|null             $role_code
 * @property string|null             $resource_type
 * @property string|null             $resource_id
 * @property string|null             $configuration_key
 * @property array<string, mixed>|null $before_state
 * @property array<string, mixed>|null $after_state
 * @property string|null             $status_before
 * @property string|null             $status_after
 * @property string|null             $version_before
 * @property string|null             $version_after
 * @property \Carbon\CarbonImmutable|null $effective_from
 * @property \Carbon\CarbonImmutable|null $effective_to
 * @property string|null             $reason
 * @property string                  $correlation_id
 * @property string|null             $session_id
 * @property string|null             $device_id
 * @property string                  $request_id
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class ConfigurationAuditEventModel extends Model
{
    use HasPublicUuid;

    protected $table = 'configuration_audit_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_state' => 'json',
            'after_state' => 'json',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Los registros de auditoría no se modifican.'));
        self::deleting(fn (): never => throw new LogicException('Los registros de auditoría no se eliminan.'));
    }
}
