<?php

namespace App\Modules\Distributor\Application\Contracts;

class CategoryVersionInfo
{
    public function __construct(
        public readonly string $categoryId,
        public readonly string $versionId,
        public readonly string $name,
        public readonly string $profitRate
    ) {}
}
