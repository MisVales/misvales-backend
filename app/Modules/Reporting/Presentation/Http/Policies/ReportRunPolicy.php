<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;

/**
 * Runs are private to their requester until cross-user visibility is specified.
 */
final class ReportRunPolicy
{
    public function view(User $actor, ReportRun $run): bool
    {
        return $run->requested_by === $actor->id;
    }
}
