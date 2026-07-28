<?php

namespace App\Modules\Notification\Application\Resolvers;

class MockRecipientResolver implements RecipientResolver
{
    public function resolve(string $eventCode, array $payload): array
    {
        // Mock de resolución, asumiendo un usuario extraído del payload (Ej: actor_user_id)
        // La implementación real dependerá de M02, M03, M10, etc.
        $userId = $payload['actor_user_id'] ?? null;
        if (! $userId) {
            return [];
        }

        return [
            [
                'recipient_key' => 'USER:'.$userId,
                'recipient_type' => 'USER',
                'user_id' => $userId,
                'application_id' => null,
                'email_snapshot' => 'mock_'.$userId.'@example.com',
                'role_snapshot' => 'MANAGER',
                'branch_id_snapshot' => $payload['branch_id'] ?? null,
                'resolution_reasons' => ['actor'],
            ],
        ];
    }
}
