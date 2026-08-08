<?php

namespace App\Modules\Organization\Infrastructure\Events;

use App\Modules\Organization\Application\Assignments\Identity\OrganizationIdentityAccess;
use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Infrastructure\Outbox\OrganizationOutboxMessage;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final readonly class DatabaseOrganizationEventPublisher implements OrganizationEventPublisher
{
    public function __construct(
        private SecurityAuditService $audit,
        private OrganizationIdentityAccess $identity,
    ) {}

    public function publish(OrganizationEvent $event): void
    {
        $actorRoles = $event->actorId === null ? [] : $this->identity->activeRoles($event->actorId);
        $payload = [
            ...$event->toArray(),
            'request_id' => request()->attributes->get('request_id'),
            'actor_roles' => $actorRoles,
        ];

        DB::transaction(function () use ($event, $payload): void {
            OrganizationOutboxMessage::query()->create([
                'id' => Str::uuid()->toString(),
                'event_id' => $event->id,
                'event_type' => $event->type->value,
                'aggregate_type' => $event->aggregateType,
                'aggregate_id' => $event->aggregateId,
                'payload' => $payload,
                'occurred_at' => $event->occurredAt,
                'available_at' => now(),
            ]);

            $this->audit->log(request(), [
                'user_id' => $event->affectedUserId,
                'actor_user_id' => $event->actorId,
                'branch_id' => $event->branchId,
                'event_type' => $event->type->value,
                'severity' => $event->outcome === 'DENIED' ? 'WARNING' : 'INFO',
                'outcome' => $event->outcome,
                'entity_type' => $event->aggregateType,
                'entity_id' => $event->aggregateId,
                'metadata' => $payload,
            ]);
        });

        Event::dispatch($event);
    }
}
