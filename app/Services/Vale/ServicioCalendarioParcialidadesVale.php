<?php

namespace App\Services\Vale;

use App\Models\Vale;
use Carbon\CarbonImmutable;

final class ServicioCalendarioParcialidadesVale
{
    private const DAYS_PER_FORTNIGHT = 15;

    private const BUSINESS_TIMEZONE = 'America/Monterrey';

    public function programar(Vale $vale, CarbonImmutable $cashedAt): int
    {
        $localCashedAt = $cashedAt->setTimezone(self::BUSINESS_TIMEZONE);

        $scheduled = 0;
        $vale->parcialidades()
            ->whereNull('due_at')
            ->orderBy('number')
            ->get()
            ->each(function ($installment) use ($localCashedAt, &$scheduled): void {
                $installment->forceFill([
                    'due_at' => $localCashedAt->addDays($installment->number * self::DAYS_PER_FORTNIGHT),
                ])->save();
                $scheduled++;
            });

        return $scheduled;
    }

    public function repararCobradosSinCalendario(): int
    {
        $scheduled = 0;

        Vale::query()
            ->where('status', 'CASHED')
            ->whereNotNull('cashed_at')
            ->whereHas('parcialidades', fn ($query) => $query->whereNull('due_at'))
            ->orderBy('id')
            ->chunkById(100, function ($vouchers) use (&$scheduled): void {
                foreach ($vouchers as $voucher) {
                    $scheduled += $this->programar($voucher, CarbonImmutable::instance($voucher->cashed_at));
                }
            });

        return $scheduled;
    }
}
