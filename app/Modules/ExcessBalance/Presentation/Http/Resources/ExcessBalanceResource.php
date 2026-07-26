<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Resources;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExcessBalanceModel */
final class ExcessBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->public_number,
            'estado' => $this->status->value,
            'relacion_origen' => [
                'id' => $this->origin_relation_id,
                'folio' => null,
            ],
            'importe_original' => (new Money((string) $this->original_amount))->publicValue(),
            'importe_pendiente_decision' => (new Money((string) $this->retained_amount))->publicValue(),
            'saldo_a_favor_disponible' => (new Money((string) $this->available_amount))->publicValue(),
            'importe_reservado' => (new Money((string) $this->reserved_refund_amount))->publicValue(),
            'importe_aplicado' => (new Money((string) $this->applied_amount))->publicValue(),
            'importe_devuelto' => (new Money((string) $this->refunded_amount))->publicValue(),
            'moneda' => $this->currency,
            'fecha_pago_origen' => $this->effective_paid_at?->toDateString(),
            'decidido_en' => $this->decided_at?->toIso8601String(),
            'lock_version' => (int) $this->lock_version,
            'acciones_permitidas' => $this->actions($request),
        ];
    }

    /** @return list<string> */
    private function actions(Request $request): array
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return [];
        }
        $actor->loadMissing('role');
        if ($actor->role->code !== RoleCode::DISTRIBUTOR
            || $actor->id !== $this->distributor_id
            || $this->status !== ExcessBalanceStatus::PENDING_DECISION) {
            return [];
        }

        return ['ELEGIR_SALDO_A_FAVOR', 'SOLICITAR_DEVOLUCION'];
    }
}
