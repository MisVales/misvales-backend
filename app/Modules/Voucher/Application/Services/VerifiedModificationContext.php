<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;

/**
 * Prueba efímera de que M09 comparó el token antes de entrar al contrato M06.
 *
 * No contiene el token y se consume una sola vez dentro de la misma petición.
 */
final class VerifiedModificationContext
{
    /** @var array{authorization_id:string,token_id:string,operation_id:string}|null */
    private ?array $scope = null;

    public function establish(string $authorizationId, string $tokenId, string $operationId): void
    {
        $this->scope = [
            'authorization_id' => $authorizationId,
            'token_id' => $tokenId,
            'operation_id' => $operationId,
        ];
    }

    public function consume(string $authorizationId, string $operationId): string
    {
        $scope = $this->scope;
        $this->scope = null;
        if (
            $scope === null
            || ! hash_equals($scope['authorization_id'], $authorizationId)
            || ! hash_equals($scope['operation_id'], $operationId)
        ) {
            throw VoucherDomainException::tokenInvalid();
        }

        return $scope['token_id'];
    }
}
