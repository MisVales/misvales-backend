<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Queries;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Contracts\RelationRiskSourcePort;

final class GetDelinquencyReview
{
    public function __construct(
        private readonly RiskQueryService $queries,
        private readonly RelationRiskSourcePort $source,
    ) {}

    /** @return array<string, mixed> */
    public function get(User $actor, string $alertNumber): array
    {
        $alert = $this->queries->alert($actor, $alertNumber);
        $reviews = [];
        foreach ($alert->relations as $relation) {
            $reviews[] = $this->source->review($relation->relation_id);
        }

        return ['alert' => $alert, 'financial_review' => $reviews];
    }
}
