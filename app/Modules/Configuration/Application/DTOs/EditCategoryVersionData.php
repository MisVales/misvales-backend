<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para editar un borrador de versión de categoría.
 */
final readonly class EditCategoryVersionData
{
    public function __construct(
        public string $versionPublicId,
        public string $name,
        public string $description,
        public string $distributorProfitRate,
        public int $lockVersion,
        public int $actorUserId,
    ) {}
}
