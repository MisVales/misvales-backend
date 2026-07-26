<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Queries;

use App\Modules\Client\Application\Contracts\ClientVersionReader;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Persistence\Models\Client;

final class EloquentClientVersionReader implements ClientVersionReader
{
    public function lockVersion(string $clientId): int
    {
        $version = Client::query()->whereKey($clientId)->value('lock_version');
        if (! is_int($version)) {
            throw ClientDomainException::notFoundOrOutOfScope();
        }

        return $version;
    }
}
