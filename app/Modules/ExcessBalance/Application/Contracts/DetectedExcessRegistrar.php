<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Contracts;

use App\Modules\ExcessBalance\Application\DTOs\DetectedExcess;

interface DetectedExcessRegistrar
{
    /**
     * Debe invocarse dentro de la misma transacción financiera de M11.
     *
     * @return array<string, mixed>
     */
    public function register(DetectedExcess $detected): array;
}
