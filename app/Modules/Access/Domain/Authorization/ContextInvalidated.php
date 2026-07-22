<?php

namespace App\Modules\Access\Domain\Authorization;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Informa que el contexto en caché de una cuenta debe descartarse inmediatamente. */
final class ContextInvalidated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly ContextInvalidationReason $reason,
        public readonly int $contextVersion,
    ) {}
}
