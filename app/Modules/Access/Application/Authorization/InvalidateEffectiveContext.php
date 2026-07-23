<?php

namespace App\Modules\Access\Application\Authorization;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\ContextInvalidated;
use App\Modules\Access\Domain\Authorization\ContextInvalidationReason;
use Illuminate\Support\Facades\DB;

/** Incrementa la versión y publica la invalidación del contexto después de confirmar la transacción. */
final class InvalidateEffectiveContext
{
    /**
     * @param  list<int>  $userIds
     */
    public function execute(array $userIds, ContextInvalidationReason $reason): void
    {
        DB::transaction(function () use ($userIds, $reason): void {
            User::query()->whereKey($userIds)->lockForUpdate()->get()->each(
                function (User $user) use ($reason): void {
                    $user->increment('context_version');
                    $version = $user->context_version;
                    DB::afterCommit(fn () => ContextInvalidated::dispatch($user->id, $reason, $version));
                },
            );
        });
    }
}
