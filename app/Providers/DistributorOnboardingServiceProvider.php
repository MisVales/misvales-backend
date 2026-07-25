<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\DistributorOnboarding\Domain\Contracts\AccountPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ConfigurationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\CreditLinePort;
use App\Modules\DistributorOnboarding\Domain\Contracts\DifferenceCatalogPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\DistributorPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ExpedientRequirementsPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\FolioGenerator;
use App\Modules\DistributorOnboarding\Domain\Contracts\MediaPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\NotificationOutboxPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ReauthenticationPort;
use App\Modules\DistributorOnboarding\Persistence\Integrations\AccessReauthenticationPort;
use App\Modules\DistributorOnboarding\Persistence\Integrations\EloquentNotificationOutbox;
use App\Modules\DistributorOnboarding\Persistence\Integrations\UnavailableAccountPort;
use App\Modules\DistributorOnboarding\Persistence\Integrations\UnavailableConfigurationPort;
use App\Modules\DistributorOnboarding\Persistence\Integrations\UnavailableCreditLinePort;
use App\Modules\DistributorOnboarding\Persistence\Integrations\UnavailableDifferenceCatalog;
use App\Modules\DistributorOnboarding\Persistence\Integrations\UnavailableDistributorPort;
use App\Modules\DistributorOnboarding\Persistence\Integrations\UnavailableExpedientRequirements;
use App\Modules\DistributorOnboarding\Persistence\Integrations\UnavailableMediaPort;
use App\Modules\DistributorOnboarding\Persistence\Integrations\UnavailableOrganizationPort;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use App\Modules\DistributorOnboarding\Persistence\UlidFolioGenerator;
use App\Modules\DistributorOnboarding\Presentation\Http\Policies\DistributorApplicationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Registra los puertos de M04 y mantiene cerradas las integraciones aún ausentes. */
final class DistributorOnboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FolioGenerator::class, UlidFolioGenerator::class);
        $this->app->bind(OrganizationPort::class, UnavailableOrganizationPort::class);
        $this->app->bind(ExpedientRequirementsPort::class, UnavailableExpedientRequirements::class);
        $this->app->bind(MediaPort::class, UnavailableMediaPort::class);
        $this->app->bind(NotificationOutboxPort::class, EloquentNotificationOutbox::class);
        $this->app->bind(DifferenceCatalogPort::class, UnavailableDifferenceCatalog::class);
        $this->app->bind(ReauthenticationPort::class, AccessReauthenticationPort::class);
        $this->app->bind(AccountPort::class, UnavailableAccountPort::class);
        $this->app->bind(DistributorPort::class, UnavailableDistributorPort::class);
        $this->app->bind(CreditLinePort::class, UnavailableCreditLinePort::class);
        $this->app->bind(ConfigurationPort::class, UnavailableConfigurationPort::class);
    }

    public function boot(): void
    {
        Gate::policy(DistributorApplication::class, DistributorApplicationPolicy::class);
    }
}
