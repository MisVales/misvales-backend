<?php

declare(strict_types=1);

namespace App\Services\Recargo;

use App\Models\RelacionDistribuidora;

final class ServicioCalculoRecargoRelacion
{
    /** @param array<string,string>|null $itemAmounts */
    /** @return array{unit_amount:string,units:int,amount:string,item_ids:list<string>,item_amounts:array<string,string>} */
    public function calcular(RelacionDistribuidora $relation, string $unitAmount, ?array $itemAmounts = null): array
    {
        $unitAmount = bcadd($unitAmount, '0', 4);
        $itemIds = $relation->partidas()->orderBy('created_at')->orderBy('id')->pluck('id')->all();
        // Las relaciones heredadas de sólo arrastre no tienen partidas propias,
        // pero siguen representando un ciclo vencido cobrable.
        if ($itemAmounts !== null) {
            $itemAmounts = collect($itemAmounts)
                ->filter(fn ($amount, $itemId): bool => in_array((string) $itemId, $itemIds, true) && is_numeric($amount) && bccomp((string) $amount, '0', 4) > 0)
                ->mapWithKeys(fn ($amount, $itemId): array => [(string) $itemId => bcadd((string) $amount, '0', 4)])
                ->all();
            $itemIds = array_values(array_keys($itemAmounts));
        }
        $units = max(1, count($itemIds));
        $amount = $itemAmounts === null
            ? bcmul($unitAmount, (string) $units, 4)
            : array_reduce($itemAmounts, static fn (string $sum, string $fee): string => bcadd($sum, $fee, 4), '0.0000');

        return [
            'unit_amount' => $unitAmount,
            'units' => $units,
            'amount' => $amount,
            'item_ids' => $itemIds,
            'item_amounts' => $itemAmounts ?? collect($itemIds)->mapWithKeys(fn (string $itemId): array => [$itemId => $unitAmount])->all(),
        ];
    }
}
