<?php

namespace App\Modules\Organization\Domain\Assignments;

final readonly class EffectiveOrganizationScope
{
    /** @var list<string> */
    private array $branchIds;

    /** @param list<string> $branchIds */
    public function __construct(
        private bool $global,
        array $branchIds = [],
    ) {
        $this->branchIds = array_values(array_unique($branchIds));
    }

    public static function global(): self
    {
        return new self(true);
    }

    /** @param list<string> $branchIds */
    public static function limitedTo(array $branchIds): self
    {
        return new self(false, $branchIds);
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    /** @return list<string> */
    public function branchIds(): array
    {
        return $this->branchIds;
    }

    public function allows(string $branchId): bool
    {
        return $this->global || in_array($branchId, $this->branchIds, true);
    }
}
