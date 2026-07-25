<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;

/** Produce una huella lógica estable de todas las secciones vigentes del expediente. */
final class ApplicationSnapshotHasher
{
    public function hash(DistributorApplication $application): string
    {
        $application->load([
            'personalData',
            'familyMembers',
            'familyReferences',
            'residences',
            'vehicles',
            'assetsLiabilities',
            'employments',
            'laborReferences',
            'commercialCredits',
        ]);

        $snapshot = $application->toArray();
        $this->sortRecursively($snapshot);

        return hash('sha256', (string) json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @param array<string|int, mixed> $value */
    private function sortRecursively(array &$value): void
    {
        if (array_is_list($value)) {
            usort($value, static function (mixed $left, mixed $right): int {
                $leftKey = is_array($left) ? (string) ($left['public_id'] ?? $left['id'] ?? '') : '';
                $rightKey = is_array($right) ? (string) ($right['public_id'] ?? $right['id'] ?? '') : '';

                return $leftKey <=> $rightKey;
            });
        } else {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }
}
