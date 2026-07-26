<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns;

use Illuminate\Support\Str;

trait UsesUuidPrimaryKey
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function bootUsesUuidPrimaryKey(): void
    {
        static::creating(function (object $model): void {
            if (! is_string($model->getKey()) || $model->getKey() === '') {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
