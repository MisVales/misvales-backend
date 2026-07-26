<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Resolution;

use App\Modules\Configuration\Application\DTOs\ResolvedProduct;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentProductRepository;
use Carbon\CarbonImmutable;

/**
 * Resuelve la versión vigente de un producto para una fecha dada (C04/C09).
 */
final class ProductResolver
{
    public function __construct(
        private readonly EloquentProductRepository $repository,
    ) {}

    /**
     * Resuelve la versión publicada vigente de un producto.
     *
     * @param  string  $productPublicId  UUID público del producto.
     * @param  CarbonImmutable  $at  Fecha efectiva.
     *
     * @throws ConfigurationException Si el producto no existe o no hay versión aplicable.
     */
    public function resolve(string $productPublicId, CarbonImmutable $at): ResolvedProduct
    {
        $product = $this->repository->findById($productPublicId);

        if ($product === null) {
            throw ConfigurationException::productNotFound();
        }

        $version = $this->repository->resolveAt($product, $at);

        if ($version === null) {
            throw ConfigurationException::productNotPublished();
        }

        return new ResolvedProduct(
            productPublicId: $product->public_id,
            versionPublicId: $version->public_id,
            versionNumber: $version->version_number,
            amount: $version->amount,
            loanCommissionRate: $version->loan_commission_rate,
            interestRatePerFortnight: $version->interest_rate_per_fortnight,
            insuranceAmount: $version->insurance_amount,
            fortnightCount: $version->fortnight_count,
            effectiveFrom: $version->effective_from,
            effectiveTo: $version->effective_to,
        );
    }
}
