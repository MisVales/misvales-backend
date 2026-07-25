<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use Illuminate\Support\Facades\DB;

/** Activa o desactiva la cartera sin afectar clientes, vales, relaciones o línea. */
final readonly class SetPortfolioTracking
{
    public function __construct(
        private DistributorProfilePort $profiles,
        private ClientAuditPort $audit,
    ) {}

    public function execute(SetPortfolioTrackingCommand $command): ClientPortfolioSetting
    {
        if (
            $command->actor->role !== RoleCode::DISTRIBUTOR
            || ! $command->actor->hasPermission(PermissionCode::CLIENTS_PORTFOLIO_WRITE_OWN->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
        $profile = $this->profiles->forAuthenticatedDistributor($command->actor->userId);

        return DB::transaction(function () use ($command, $profile): ClientPortfolioSetting {
            $assignment = ClientDistributorAssignment::query()
                ->where('client_id', $command->clientId)
                ->where('distributor_id', $profile->distributorId)
                ->where('active_slot', true)
                ->lockForUpdate()
                ->first();
            if ($assignment === null) {
                throw ClientDomainException::notFoundOrOutOfScope();
            }

            $setting = ClientPortfolioSetting::query()
                ->where('assignment_id', $assignment->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($setting->lock_version !== $command->expectedVersion) {
                throw ClientDomainException::versionConflict('PORTFOLIO_VERSION_CONFLICT');
            }

            $setting->forceFill([
                'tracking_enabled' => $command->enabled,
                'lock_version' => $setting->lock_version + 1,
                'updated_by' => $command->actor->userId,
            ])->save();

            $this->audit->record(
                'CLIENT_PORTFOLIO_TRACKING_CHANGED',
                $command->clientId,
                $command->actor,
                $profile->distributorId,
                null,
                ['tracking_enabled'],
                'SUCCESS',
                $command->requestId,
            );

            return $setting->refresh();
        }, 3);
    }
}
