<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Asigna UUID opacos a las entidades persistentes de M06. */
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
            if ($model->getAttribute($model->getKeyName()) === null) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
