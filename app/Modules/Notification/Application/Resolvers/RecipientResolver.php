<?php

namespace App\Modules\Notification\Application\Resolvers;

interface RecipientResolver
{
    /**
     * Resuelve los destinatarios basados en el código del evento y el payload.
     *
     * @return array<int, array{
     *   recipient_key: string,
     *   recipient_type: string,
     *   user_id: ?string,
     *   application_id: ?string,
     *   email_snapshot: ?string,
     *   role_snapshot: ?string,
     *   branch_id_snapshot: ?string,
     *   resolution_reasons: array
     * }>
     */
    public function resolve(string $eventCode, array $payload): array;
}
