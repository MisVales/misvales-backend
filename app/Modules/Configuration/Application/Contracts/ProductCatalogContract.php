<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Contracts;

use App\Modules\Configuration\Application\DTOs\ResolvedProduct;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use Carbon\CarbonImmutable;

/**
 * Contrato de lectura del catálogo de productos para módulos consumidores.
 *
 * M08 utiliza este contrato para obtener la versión vigente de un producto.
 */
interface ProductCatalogContract
{
    /**
     * Resuelve la versión vigente de un producto.
     *
     * @throws ConfigurationException
     */
    public function resolve(string $productPublicId, CarbonImmutable $at): ResolvedProduct;
}
