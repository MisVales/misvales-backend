<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\AuthorizedChanges;

use App\Modules\Client\Application\Contracts\CorrectableClientFieldRegistry;

/** Lista propiedad de M06; ningún consumidor define nombres de columnas. */
final class DefaultCorrectableClientFieldRegistry implements CorrectableClientFieldRegistry
{
    private const FIELDS = [
        'given_names',
        'surnames',
        'curp',
        'rfc',
        'birth_date',
        'birth_place',
        'birth_state',
        'birth_city',
        'address',
        'official_identification_media_id',
        'address_proof_media_id',
        'bank_account',
    ];

    public function all(): array
    {
        return self::FIELDS;
    }

    public function containsExactly(array $fields): bool
    {
        return $fields !== []
            && count($fields) === count(array_unique($fields))
            && array_diff($fields, self::FIELDS) === [];
    }
}
