<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Providers;

use App\Modules\Configuration\Application\Contracts\ConfigurationReadContract;
use App\Modules\Configuration\Application\Contracts\ProductCatalogContract;
use App\Modules\Configuration\Application\Contracts\RedemptionPeriodContract;
use App\Modules\Configuration\Application\Resolution\ProductResolver;
use App\Modules\Configuration\Application\Services\ConfigurationReadService;
use App\Modules\Configuration\Application\Services\RedemptionPeriodReadService;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider del módulo de Configuraciones y Catálogos (M03).
 *
 * Registra contratos de lectura para módulos consumidores.
 */
final class ConfigurationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConfigurationReadContract::class, ConfigurationReadService::class);
        $this->app->bind(ProductCatalogContract::class, ProductResolver::class);
        $this->app->bind(RedemptionPeriodContract::class, RedemptionPeriodReadService::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Presentation/Http/routes.php');
    }
}
