<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Security\ActorContextFactory;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;

/** Policy de recurso que reutiliza las reglas de alcance autoritativas. */
final readonly class DistributorApplicationPolicy
{
    public function __construct(
        private ActorContextFactory $contexts,
        private OnboardingAuthorizer $authorizer,
    ) {}

    public function viewAny(User $user): bool
    {
        try {
            $actor = $this->contexts->fromUser($user);

            return $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_ASSIGNED->value)
                || $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_BRANCH->value)
                || $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_GLOBAL->value);
        } catch (OnboardingDomainException) {
            return false;
        }
    }

    public function view(User $user, DistributorApplication $application): bool
    {
        try {
            $this->authorizer->assertCanView($this->contexts->fromUser($user), $application);

            return true;
        } catch (OnboardingDomainException) {
            return false;
        }
    }
}
