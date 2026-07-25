<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Contrato interno por el que M08 incorpora un cargo informativo confirmado. */
interface RecordClientVoucherReference
{
    public function handle(RecordClientVoucherReferenceCommand $command): void;
}
