<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationAction;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStateMachine;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;

/** Aplica y registra una transición autorizada sobre la raíz bloqueada. */
final readonly class ApplicationTransitioner
{
    public function __construct(
        private ApplicationStateMachine $stateMachine,
        private WorkflowRecorder $recorder,
    ) {}

    public function transition(
        DistributorApplication $application,
        ActorContext $actor,
        ApplicationAction $action,
        ?string $reason,
        ?string $result,
        OperationMetadata $metadata,
        string $eventType,
    ): void {
        $previous = $application->status;
        $next = $this->stateMachine->destination($previous, $action);

        $application->forceFill([
            'status' => $next,
            'result' => $next->isTerminal() ? $next : null,
            'lock_version' => $application->lock_version + 1,
        ])->save();

        $this->recorder->transition(
            application: $application,
            actor: $actor,
            previous: $previous,
            next: $next,
            action: $action->value,
            reason: $reason,
            result: $result,
            metadata: $metadata,
            eventType: $eventType,
        );
    }
}
