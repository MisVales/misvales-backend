<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Inicializa UUID como clave primaria no incremental de los modelos de M11. */
trait UsesUuidPrimaryKey
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function bootUsesUuidPrimaryKey(): void
    {
        static::creating(function (Model $model): void {
            if (! is_string($model->getKey()) || $model->getKey() === '') {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
