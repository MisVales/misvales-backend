<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

trait HasOptimisticLocking
{
    /**
     * Boot the trait.
     */
    public static function bootHasOptimisticLocking()
    {
        static::updating(function (Model $model) {
            $model->lock_version = $model->lock_version + 1;
        });
    }

    /**
     * Add the lock_version constraint to the save query.
     */
    protected function setKeysForSaveQuery($query)
    {
        $query = parent::setKeysForSaveQuery($query);

        if (array_key_exists('lock_version', $this->getAttributes())) {
            $query->where('lock_version', $this->getOriginal('lock_version') ?? 0);
        }

        return $query;
    }

    /**
     * Perform a model update operation.
     */
    protected function performUpdate(Builder $query)
    {
        $affected = parent::performUpdate($query);

        if ($affected === 0 && $this->isDirty()) {
            throw new \App\Exceptions\BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia: El registro fue modificado por otro usuario. (Stale Data)', 409);
        }

        return $affected;
    }
}
