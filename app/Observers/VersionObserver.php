<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\RedemptionPeriod;
use Illuminate\Support\Facades\Request;

class VersionObserver
{
    public function saved($model)
    {
        $request = Request::instance();
        $user = $request->user();
        
        $scope = $user ? $user->roleScopes()->first() : null;
        $roleName = $scope ? $scope->role->name : 'SISTEMA';

        if (!$model->wasChanged() && !$model->wasRecentlyCreated) {
            return;
        }

        $eventName = $this->determineEventName($model);

        $previousValue = $model->wasRecentlyCreated ? null : $model->getOriginal();
        $newValue = $model->wasRecentlyCreated ? $model->toArray() : $model->getChanges();

        // Enmascarar información sensible en Auditoría (Punto 90)
        if ($model instanceof ConfigurationVersion && isset($model->definition) && $model->definition->is_sensitive) {
            if (isset($previousValue['value'])) $previousValue['value'] = '********';
            if (isset($newValue['value'])) $newValue['value'] = '********';
        }

        AuditLog::create([
            'actor_id' => $user?->id,
            'actor_role' => $roleName,
            'branch_id' => null, // Catálogos y configuraciones son globales
            'entity_type' => get_class($model),
            'event_name' => $eventName,
            'entity_id' => $model->id,
            'version' => $model->version ?? null,
            'previous_value' => $previousValue,
            'new_value' => $newValue,
            'effective_from' => $model->effective_from ?? $model->starts_at ?? null,
            'effective_to' => $model->effective_to ?? $model->ends_at ?? null,
            'reason' => $model->reason ?? 'Cambio de estado o actualización detectado.',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->attributes->get('request_id') ?? $request->header('X-Request-Id'),
            'result' => 'SUCCESS',
        ]);
    }

    private function determineEventName($model): string
    {
        if ($model instanceof ConfigurationDefinition) {
            return 'Definición creada.';
        }
        
        if ($model instanceof Category) {
            if ($model->wasRecentlyCreated) return 'Categoría creada.';
            if ($model->status === \App\Enums\BaseStatus::INACTIVE) return 'Categoría desactivada.';
            return 'Categoría modificada.';
        }
        
        if ($model instanceof Product) {
            if ($model->wasRecentlyCreated) return 'Producto creado.';
            if ($model->status === \App\Enums\BaseStatus::INACTIVE) return 'Producto desactivado.';
            return 'Producto modificado.';
        }

        if ($model instanceof ConfigurationVersion) {
            if ($model->wasRecentlyCreated) return 'Versión creada.';
            
            $changes = $model->getChanges();
            if (isset($changes['status'])) {
                $statusValue = $changes['status'] instanceof \App\Enums\VersionStatus ? $changes['status']->value : $changes['status'];
                if ($statusValue === \App\Enums\VersionStatus::PUBLISHED->value) {
                    if ($model->definition->key === 'DIA_CORTE_GLOBAL') return 'Día global de corte modificado.';
                    if ($model->definition->key === 'REGLA_FECHA_LIMITE') return 'Regla de fecha límite modificada.';
                    return 'Versión publicada.';
                }
                if ($statusValue === \App\Enums\VersionStatus::INACTIVE->value) {
                    return 'Versión desactivada.';
                }
            }
            return 'Versión modificada.';
        }
        
        if ($model instanceof CategoryVersion) {
            if ($model->wasRecentlyCreated) return 'Versión creada.';
            $changes = $model->getChanges();
            if (isset($changes['status'])) {
                $statusValue = $changes['status'] instanceof \App\Enums\VersionStatus ? $changes['status']->value : $changes['status'];
                if ($statusValue === \App\Enums\VersionStatus::PUBLISHED->value) return 'Versión de categoría publicada.';
                if ($statusValue === \App\Enums\VersionStatus::INACTIVE->value) return 'Versión desactivada.';
            }
            return 'Versión modificada.';
        }
        
        if ($model instanceof ProductVersion) {
            if ($model->wasRecentlyCreated) return 'Versión creada.';
            $changes = $model->getChanges();
            if (isset($changes['status'])) {
                $statusValue = $changes['status'] instanceof \App\Enums\VersionStatus ? $changes['status']->value : $changes['status'];
                if ($statusValue === \App\Enums\VersionStatus::PUBLISHED->value) return 'Versión de producto publicada.';
                if ($statusValue === \App\Enums\VersionStatus::INACTIVE->value) return 'Versión desactivada.';
            }
            return 'Versión modificada.';
        }
        
        if ($model instanceof RedemptionPeriod) {
            if ($model->wasRecentlyCreated) return 'Periodo de canje creado.';
            $changes = $model->getChanges();
            if (isset($changes['status'])) {
                $statusValue = $changes['status'] instanceof \App\Enums\RedemptionPeriodStatus ? $changes['status']->value : $changes['status'];
                if ($statusValue === \App\Enums\RedemptionPeriodStatus::PUBLISHED->value) return 'Periodo de canje publicado.';
                if ($statusValue === \App\Enums\RedemptionPeriodStatus::CANCELLED->value) return 'Periodo de canje cancelado.';
            }
            return 'Periodo de canje modificado.';
        }

        return 'Evento detectado.';
    }
}
