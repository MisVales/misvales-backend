<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\BankAccounts;

use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientChanges;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientChangesCommand;
use App\Modules\Client\Persistence\Models\ClientBankAccount;

/** Fachada de la ruta bancaria que aplica M09 y devuelve solo la versión enmascarable. */
final readonly class ReplaceAuthorizedBankAccount
{
    public function __construct(private ApplyAuthorizedClientChanges $changes) {}

    public function execute(ApplyAuthorizedClientChangesCommand $command): ClientBankAccount
    {
        $this->changes->handle($command);

        return ClientBankAccount::query()
            ->where('client_id', $command->clientId)
            ->where('active_slot', true)
            ->firstOrFail();
    }
}
