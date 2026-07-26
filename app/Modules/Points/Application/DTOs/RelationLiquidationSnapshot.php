<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\DTOs;

use App\Modules\Points\Domain\Enums\LiquidationClassification;
use App\Modules\Points\Domain\ValueObjects\PointRuleSnapshot;
use Carbon\CarbonImmutable;

/**
 * Contrato versionado M10/M11 consumido por M13.
 *
 * La base procede de M10 y la clasificación/fecha efectiva de M11. M13 no
 * selecciona vales, suma parcialidades ni vuelve a clasificar la liquidación.
 */
final readonly class RelationLiquidationSnapshot
{
    public function __construct(
        public string $relationId,
        public int $distributorId,
        public int $branchId,
        public LiquidationClassification $classification,
        public CarbonImmutable $effectiveLiquidationAt,
        public string $financialStateVersion,
        public string $sourceEventId,
        public bool $isLiquidated,
        public string $productsCapitalBasis,
        public PointRuleSnapshot $ruleSnapshot,
        public CarbonImmutable $earlyPaymentStartsAt,
        public CarbonImmutable $earlyPaymentEndsAt,
    ) {}
}
