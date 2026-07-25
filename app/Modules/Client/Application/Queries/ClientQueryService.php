<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Queries;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Client\Domain\Clients\CurpNormalizer;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Security\ExactMatchHmac;
use App\Modules\Client\Persistence\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Consulta clientes aplicando alcance en PostgreSQL antes de materializar resultados. */
final readonly class ClientQueryService
{
    public function __construct(
        private DistributorProfilePort $profiles,
        private CurpNormalizer $curpNormalizer,
        private ExactMatchHmac $hmac,
        private ClientAuditPort $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Client>
     */
    public function paginate(ClientActorContext $actor, array $filters, string $requestId): LengthAwarePaginator
    {
        $query = $this->scoped($actor)
            ->with(['currentAssignment.branch', 'portfolioSettings'])
            ->select('clients.*');

        if (is_string($filters['name'] ?? null) && trim($filters['name']) !== '') {
            $name = mb_strtolower(trim((string) $filters['name']), 'UTF-8');
            $query->whereRaw("LOWER(given_names || ' ' || surnames) LIKE ? ESCAPE '\\\\'", [
                '%'.$this->escapeLike($name).'%',
            ]);
        }
        if (is_string($filters['curp'] ?? null) && trim($filters['curp']) !== '') {
            if (! $actor->hasPermission(PermissionCode::CLIENTS_VIEW_SENSITIVE_AUTHORIZED->value)) {
                throw ClientDomainException::authorizationDenied();
            }
            $curp = $this->curpNormalizer->normalize((string) $filters['curp']);
            $query->where('curp_hmac', $this->hmac->make($curp));
        }
        if (is_string($filters['distributor_id'] ?? null)) {
            $query->whereHas('currentAssignment', fn (Builder $assignment): Builder => $assignment
                ->where('distributor_id', (string) $filters['distributor_id']));
        }
        if (is_string($filters['branch_id'] ?? null)) {
            $ids = $this->profiles->activeDistributorIdsForBranchPublicId((string) $filters['branch_id']);
            $query->whereHas('currentAssignment', fn (Builder $assignment): Builder => $assignment
                ->whereIn('distributor_id', $ids));
        }
        if (is_string($filters['registered_from'] ?? null)) {
            $query->whereDate('clients.created_at', '>=', (string) $filters['registered_from']);
        }
        if (is_string($filters['registered_to'] ?? null)) {
            $query->whereDate('clients.created_at', '<=', (string) $filters['registered_to']);
        }
        if (array_key_exists('portfolio_tracking_enabled', $filters)) {
            $enabled = filter_var($filters['portfolio_tracking_enabled'], FILTER_VALIDATE_BOOL);
            $query->whereHas('portfolioSettings', fn (Builder $setting): Builder => $setting
                ->whereHas('assignment', fn (Builder $assignment): Builder => $assignment->where('active_slot', true))
                ->where('tracking_enabled', $enabled));
        }

        $sort = (string) ($filters['sort'] ?? 'registered_at');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $column = match ($sort) {
            'name' => 'given_names',
            'registered_at' => 'created_at',
            default => throw ClientDomainException::portfolioInvalid('El ordenamiento solicitado no está permitido.'),
        };
        $query->orderBy($column, $direction)->orderBy('clients.id', $direction);

        $maximum = (int) config('client.pagination.maximum', 100);
        $perPage = min(max((int) ($filters['per_page'] ?? config('client.pagination.default', 20)), 1), $maximum);

        $page = $query->paginate($perPage);
        $this->attachCurrentProfiles($page->items());
        if (is_string($filters['curp'] ?? null) && trim($filters['curp']) !== '') {
            $this->audit->record(
                'CLIENT_CURP_SEARCHED',
                null,
                $actor,
                null,
                null,
                [],
                'SUCCESS',
                $requestId,
            );
        }

        return $page;
    }

    public function findVisible(string $clientId, ClientActorContext $actor, string $requestId): Client
    {
        $client = $this->scoped($actor)
            ->with([
                'currentAssignment.branch',
                'currentAddress',
                'currentBankAccount',
                'currentDocuments',
                'assignmentHistory.branch',
                'portfolioSettings',
            ])
            ->where('clients.id', $clientId)
            ->first();

        if ($client === null) {
            $this->audit->record(
                'CLIENT_SCOPE_ACCESS_DENIED',
                null,
                $actor,
                null,
                null,
                [],
                'DENIED',
                $requestId,
            );
            throw ClientDomainException::notFoundOrOutOfScope();
        }
        $profile = $this->profiles->activeById((string) $client->currentAssignment?->distributor_id);
        $client->setAttribute('resolved_distributor_profile', $profile);
        $this->audit->record(
            'CLIENT_SENSITIVE_DATA_VIEWED',
            $client->id,
            $actor,
            $profile->distributorId,
            null,
            ['identity', 'address', 'documents', 'bank_account_masked'],
            'SUCCESS',
            $requestId,
        );

        return $client;
    }

    /** @return Builder<Client> */
    public function scoped(ClientActorContext $actor): Builder
    {
        $query = Client::query()
            ->whereHas('currentAssignment');

        if ($actor->hasPermission(PermissionCode::CLIENTS_VIEW_GLOBAL->value)) {
            return $query;
        }

        if (
            $actor->hasPermission(PermissionCode::CLIENTS_VIEW_BRANCH->value)
            && $actor->branchId !== null
        ) {
            $ids = $this->profiles->activeDistributorIdsForBranch($actor->branchId);

            return $query->whereHas('currentAssignment', fn (Builder $assignment): Builder => $assignment
                ->whereIn('distributor_id', $ids));
        }

        if (
            $actor->role === RoleCode::COORDINATOR
            && $actor->hasPermission(PermissionCode::CLIENTS_VIEW_ASSIGNED->value)
        ) {
            $ids = $this->profiles->activeDistributorIdsForCoordinator($actor->userId);

            return $query->whereHas('currentAssignment', fn (Builder $assignment): Builder => $assignment
                ->whereIn('distributor_id', $ids));
        }

        if (
            $actor->role === RoleCode::DISTRIBUTOR
            && $actor->hasPermission(PermissionCode::CLIENTS_VIEW_ASSIGNED->value)
        ) {
            $profile = $this->profiles->forAuthenticatedDistributor($actor->userId);

            return $query->whereHas('currentAssignment', fn (Builder $assignment): Builder => $assignment
                ->where('distributor_id', $profile->distributorId));
        }

        throw ClientDomainException::authorizationDenied();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @param list<Client> $clients */
    private function attachCurrentProfiles(array $clients): void
    {
        if ($clients === []) {
            return;
        }

        $distributorIds = [];
        foreach ($clients as $client) {
            if ($client->currentAssignment !== null) {
                $distributorIds[] = $client->currentAssignment->distributor_id;
            }
        }
        $profiles = $this->profiles->activeByIds(array_values(array_unique($distributorIds)));
        foreach ($clients as $client) {
            $distributorId = $client->currentAssignment?->distributor_id;
            if ($distributorId === null || ! isset($profiles[$distributorId])) {
                throw ClientDomainException::integrationUnavailable('M05_DISTRIBUTOR_PROFILE');
            }
            $client->setAttribute('resolved_distributor_profile', $profiles[$distributorId]);
        }
    }
}
