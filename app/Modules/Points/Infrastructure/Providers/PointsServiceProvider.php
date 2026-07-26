<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Providers;

use App\Modules\Points\Application\Contracts\RelationPointSource;
use App\Modules\Points\Infrastructure\Integrations\UnavailableRelationPointSource;
use Illuminate\Support\ServiceProvider;

final class PointsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RelationPointSource::class, UnavailableRelationPointSource::class);
    }
}
