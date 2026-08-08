<?php

namespace App\Modules\Organization\Domain\Events;

use DateTimeImmutable;

final readonly class OrganizationEvent
{
    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $notifyUserIds
     */
    public function __construct(
        public string $id,
        public OrganizationEventType $type,
        public string $aggregateType,
        public string $aggregateId,
        public ?string $actorId,
        public ?string $affectedUserId = null,
        public ?string $branchId = null,
        public ?string $roleId = null,
        public ?string $previousScope = null,
        public ?string $newScope = null,
        public ?string $reason = null,
        public array $details = [],
        public array $notifyUserIds = [],
        public string $outcome = 'SUCCESS',
        public DateTimeImmutable $occurredAt = new DateTimeImmutable,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->id,
            'event_type' => $this->type->value,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'actor_id' => $this->actorId,
            'affected_user_id' => $this->affectedUserId,
            'branch_id' => $this->branchId,
            'role_id' => $this->roleId,
            'previous_scope' => $this->previousScope,
            'new_scope' => $this->newScope,
            'reason' => $this->reason,
            'details' => $this->details,
            'notify_user_ids' => $this->notifyUserIds,
            'outcome' => $this->outcome,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
