<?php

namespace App\Modules\Access\Application\Security;

use App\Modules\Access\Domain\Security\RiskAssessment;
use App\Modules\Access\Domain\Security\RiskLevel;
use App\Modules\Access\Domain\Security\RiskResponse;

final class DeterministicRiskEngine
{
    /**
     * @param  array<string, mixed>  $signals
     */
    public function assess(string $eventType, array $signals): RiskAssessment
    {
        if ($eventType === 'REFRESH_TOKEN_REUSE_DETECTED' || ($signals['refresh_reuse'] ?? false) === true) {
            return new RiskAssessment(
                RiskLevel::CRITICAL,
                RiskResponse::REVOKE_ALL_AND_OPEN_INCIDENT,
                ['REFRESH_TOKEN_REUSE'],
                100,
            );
        }

        if (($signals['impossible_travel'] ?? false) === true
            && (bool) config('access.risk.impossible_travel_rejects', true)) {
            return new RiskAssessment(
                RiskLevel::HIGH,
                RiskResponse::REJECT_AND_REVOKE_SESSION,
                ['IMPOSSIBLE_TRAVEL'],
                80,
            );
        }

        $failureThreshold = (int) config('access.risk.high_failure_threshold', 10);
        if ((int) ($signals['recent_failures'] ?? 0) >= $failureThreshold) {
            return new RiskAssessment(
                RiskLevel::HIGH,
                RiskResponse::REJECT_AND_REVOKE_SESSION,
                ['EXCESSIVE_RECENT_FAILURES'],
                75,
            );
        }

        $mediumRules = [];
        if (($signals['new_location'] ?? false) === true
            && (bool) config('access.risk.new_location_requires_mfa', true)) {
            $mediumRules[] = 'NEW_COARSE_LOCATION';
        }
        if (($signals['context_changed'] ?? false) === true) {
            $mediumRules[] = 'SECURITY_CONTEXT_CHANGED';
        }
        if (($signals['recent_recovery'] ?? false) === true || ($signals['recent_mfa_change'] ?? false) === true) {
            $mediumRules[] = 'RECENT_CREDENTIAL_RECOVERY_OR_MFA_CHANGE';
        }

        if ($mediumRules !== []) {
            return new RiskAssessment(
                RiskLevel::MEDIUM,
                RiskResponse::REQUIRE_MFA,
                $mediumRules,
                50,
            );
        }

        $rules = [];
        if (($signals['network_changed'] ?? false) === true) {
            $rules[] = ($signals['mobile_network'] ?? false) === true
                ? 'MOBILE_NETWORK_CHANGE_RECORDED'
                : 'NETWORK_CHANGE_RECORDED';
        }

        return new RiskAssessment(
            RiskLevel::LOW,
            RiskResponse::CONTINUE_AND_RECORD,
            $rules === [] ? ['NO_ELEVATED_SIGNAL'] : $rules,
            10,
        );
    }
}
