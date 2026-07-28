<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Modules\Configuration\Application\Contracts\ProductCatalogContract;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductModel;
use App\Modules\Voucher\Application\Contracts\VoucherConfigurationGateway;
use App\Modules\Voucher\Application\DTOs\CategoryConfiguration;
use App\Modules\Voucher\Application\DTOs\ProductConfiguration;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Domain\ValueObjects\Money;
use App\Modules\Voucher\Domain\ValueObjects\Percentage;
use Carbon\CarbonImmutable;

/** Convierte los DTO públicos de M03 en objetos exactos de cálculo de M08. */
final readonly class EloquentVoucherConfigurationGateway implements VoucherConfigurationGateway
{
    public function __construct(private ProductCatalogContract $products) {}

    public function product(string $productId, CarbonImmutable $at): ProductConfiguration
    {
        try {
            $product = ProductModel::query()
                ->where('public_id', $productId)
                ->where('status', VersionStatus::PUBLISHED->value)
                ->first();
            if ($product === null) {
                throw VoucherDomainException::productUnavailable();
            }
            $resolved = $this->products->resolve($productId, $at);

            return new ProductConfiguration(
                productId: $resolved->productPublicId,
                versionId: $resolved->versionPublicId,
                version: $resolved->versionNumber,
                name: 'Producto '.$resolved->amount,
                capital: new Money($resolved->amount),
                commissionRate: new Percentage($resolved->loanCommissionRate),
                interestRate: new Percentage($resolved->interestRatePerFortnight),
                insurance: new Money($resolved->insuranceAmount),
                fortnights: $resolved->fortnightCount,
            );
        } catch (ConfigurationException) {
            throw VoucherDomainException::productUnavailable();
        } catch (VoucherDomainException $exception) {
            if ($exception->errorCode() === 'PRODUCT_NOT_AVAILABLE') {
                throw $exception;
            }

            throw VoucherDomainException::productIncomplete();
        }
    }

    public function category(string $categoryId, string $versionId, CarbonImmutable $at): CategoryConfiguration
    {
        $category = CategoryModel::query()
            ->where('public_id', $categoryId)
            ->where('status', VersionStatus::PUBLISHED->value)
            ->first();
        if ($category === null) {
            throw VoucherDomainException::categoryUnavailable();
        }
        $version = CategoryVersionModel::query()
            ->where('public_id', $versionId)
            ->where('category_id', $category->id)
            ->where('status', VersionStatus::PUBLISHED->value)
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->lockForUpdate()
            ->first();
        if ($version === null) {
            throw VoucherDomainException::categoryUnavailable();
        }

        try {
            return new CategoryConfiguration(
                categoryId: $category->public_id,
                versionId: $version->public_id,
                version: $version->version_number,
                name: $version->name,
                profitRate: new Percentage($version->distributor_profit_rate),
            );
        } catch (VoucherDomainException) {
            throw VoucherDomainException::categoryUnavailable();
        }
    }
}
