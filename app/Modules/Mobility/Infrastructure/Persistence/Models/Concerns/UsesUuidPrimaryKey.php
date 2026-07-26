<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence\Models\Concerns;

use Illuminate\Support\Str;

trait UsesUuidPrimaryKey
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function bootUsesUuidPrimaryKey(): void
    {
        static::creating(static function ($model): void {
            if (! is_string($model->getKey()) || $model->getKey() === '') {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
