<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Portfolio\PortfolioNoteSanitizer;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioEntry;
use App\Modules\Client\Persistence\Models\ClientPortfolioEntryRevision;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Aplica una corrección optimista y registra una revisión inmutable. */
final readonly class UpdatePortfolioEntry
{
    public function __construct(
        private DistributorProfilePort $profiles,
        private ClientAuditPort $audit,
        private PortfolioNoteSanitizer $notes,
    ) {}

    public function execute(UpdatePortfolioEntryCommand $command): ClientPortfolioEntry
    {
        if (
            $command->actor->role !== RoleCode::DISTRIBUTOR
            || ! $command->actor->hasPermission(PermissionCode::CLIENTS_PORTFOLIO_WRITE_OWN->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
        $profile = $this->profiles->forAuthenticatedDistributor($command->actor->userId);

        return DB::transaction(function () use ($command, $profile): ClientPortfolioEntry {
            $assignment = ClientDistributorAssignment::query()
                ->where('client_id', $command->clientId)
                ->where('distributor_id', $profile->distributorId)
                ->where('active_slot', true)
                ->lockForUpdate()
                ->first();
            if ($assignment === null) {
                throw ClientDomainException::notFoundOrOutOfScope();
            }

            $entry = ClientPortfolioEntry::query()
                ->where('id', $command->entryId)
                ->where('client_id', $command->clientId)
                ->where('distributor_id', $profile->distributorId)
                ->lockForUpdate()
                ->first();
            if ($entry === null) {
                throw ClientDomainException::notFoundOrOutOfScope();
            }
            if ($entry->lock_version !== $command->expectedVersion) {
                throw ClientDomainException::versionConflict('PORTFOLIO_VERSION_CONFLICT');
            }

            $newNote = $command->note === null ? $entry->note : $this->notes->normalize($command->note);
            $newStatus = $command->status ?? $entry->informational_status;

            $revision = new ClientPortfolioEntryRevision;
            $revision->forceFill([
                'id' => (string) Str::uuid(),
                'entry_id' => $entry->id,
                'previous_note' => $entry->note,
                'new_note' => $newNote,
                'previous_status' => $entry->informational_status,
                'new_status' => $newStatus,
                'previous_version' => $entry->lock_version,
                'changed_by' => $command->actor->userId,
                'changed_at' => now(),
            ])->save();

            $entry->forceFill([
                'note' => $newNote,
                'informational_status' => $newStatus,
                'lock_version' => $entry->lock_version + 1,
            ])->save();

            $setting = ClientPortfolioSetting::query()
                ->where('assignment_id', $assignment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $setting->forceFill([
                'lock_version' => $setting->lock_version + 1,
                'updated_by' => $command->actor->userId,
            ])->save();

            $this->audit->record(
                'CLIENT_PORTFOLIO_ENTRY_CORRECTED',
                $command->clientId,
                $command->actor,
                $profile->distributorId,
                null,
                ['informational_status', 'note'],
                'SUCCESS',
                $command->requestId,
            );

            return $entry->refresh();
        }, 3);
    }
}
