<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait UsesUuidPrimaryKey
{
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    protected static function bootUsesUuidPrimaryKey(): void
    {
        static::creating(function (Model $model): void {
            if (! is_string($model->getKey()) || $model->getKey() === '') {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
