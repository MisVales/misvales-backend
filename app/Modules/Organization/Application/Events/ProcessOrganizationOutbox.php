<?php

namespace App\Modules\Organization\Application\Events;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Notifications\OrganizationChangeNotification;
use App\Modules\Organization\Infrastructure\Outbox\OrganizationOutboxMessage;

final class ProcessOrganizationOutbox
{
    public function handle(int $limit = 100): int
    {
        $messages = OrganizationOutboxMessage::query()
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->oldest('occurred_at')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            $message->increment('attempts');
            $recipientIds = $message->payload['notify_user_ids'] ?? [];

            if ($recipientIds === [] && $message->event_type === 'ORGANIZATION_SCOPE_DENIED') {
                $recipientIds = User::query()
                    ->where('state', 'ACTIVE')
                    ->whereHas('roleScopes', fn ($query) => $query
                        ->where('status', 'ACTIVE')
                        ->whereNull('revoked_at')
                        ->where('scope_type', 'GLOBAL')
                        ->whereHas('role', fn ($role) => $role->where('code', 'general_manager')))
                    ->pluck('id')
                    ->all();
            }

            User::query()
                ->whereIn('id', $recipientIds)
                ->each(fn (User $user) => $user->notify(
                    new OrganizationChangeNotification($message->payload),
                ));

            $message->forceFill(['published_at' => now()])->save();
        }

        return $messages->count();
    }
}
