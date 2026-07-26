<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Persistence\Models\Concerns;

use Illuminate\Support\Str;

trait UsesUuidPrimaryKey
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function bootUsesUuidPrimaryKey(): void
    {
        static::creating(function (self $model): void {
            $model->setAttribute($model->getKeyName(), $model->getAttribute($model->getKeyName()) ?: (string) Str::uuid());
        });
    }
}
