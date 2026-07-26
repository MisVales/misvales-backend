<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns;

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
        static::creating(function ($model): void {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
