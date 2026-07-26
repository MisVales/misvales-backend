<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Registro público de identificadores corregibles que M09 puede autorizar. */
interface CorrectableClientFieldRegistry
{
    /** @return list<string> */
    public function all(): array;

    /** @param list<string> $fields */
    public function containsExactly(array $fields): bool;
}
