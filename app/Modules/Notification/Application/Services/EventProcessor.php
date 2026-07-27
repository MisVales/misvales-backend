<?php

namespace App\Modules\Notification\Application\Services;

class EventProcessor
{
    /**
     * Valida si un evento pertenece al catálogo (EV-001 a EV-097)
     */
    public function isSupported(string $eventCode): bool
    {
        if (preg_match('/^EV-0[0-9]{2}$/', $eventCode)) {
            $number = (int) str_replace('EV-', '', $eventCode);
            return $number >= 1 && $number <= 97;
        }
        return false;
    }

    /**
     * Valida la estructura mínima (Esquema)
     */
    public function validateSchema(array $payload): bool
    {
        // Validación dummy para el ejemplo
        return isset($payload['event_id']);
    }
}
