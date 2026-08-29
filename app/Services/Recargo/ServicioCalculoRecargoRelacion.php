<?php

declare(strict_types=1);

namespace App\Services\Recargo;

use App\Models\RelacionDistribuidora;

final class ServicioCalculoRecargoRelacion
{
    /** @return array{unit_amount:string,units:int,amount:string,item_ids:list<string>} */
    public function calcular(RelacionDistribuidora $relation, string $unitAmount): array
    {
        $unitAmount = bcadd($unitAmount, '0', 4);
        $itemIds = $relation->partidas()->orderBy('created_at')->orderBy('id')->pluck('id')->all();
        // Las relaciones heredadas de sólo arrastre no tienen partidas propias,
        // pero siguen representando un ciclo vencido cobrable.
        $units = max(1, count($itemIds));

        return [
            'unit_amount' => $unitAmount,
            'units' => $units,
            'amount' => bcmul($unitAmount, (string) $units, 4),
            'item_ids' => $itemIds,
        ];
    }
}
