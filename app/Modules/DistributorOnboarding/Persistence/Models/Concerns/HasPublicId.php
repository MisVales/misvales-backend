<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('public_id') === null) {
                $model->setAttribute('public_id', (string) Str::uuid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
