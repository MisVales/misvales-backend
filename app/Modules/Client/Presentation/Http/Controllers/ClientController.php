<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\BankAccounts\BankAccountQueryService;
use App\Modules\Client\Application\BankAccounts\ReplaceAuthorizedBankAccount;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientChangesCommand;
use App\Modules\Client\Application\Portfolio\PortfolioQueryService;
use App\Modules\Client\Application\Portfolio\RecordPortfolioEntry;
use App\Modules\Client\Application\Portfolio\RecordPortfolioEntryCommand;
use App\Modules\Client\Application\Portfolio\UpdatePortfolioEntry;
use App\Modules\Client\Application\Portfolio\UpdatePortfolioEntryCommand;
use App\Modules\Client\Application\Queries\ClientQueryService;
use App\Modules\Client\Application\Registration\AddressInput;
use App\Modules\Client\Application\Registration\RegisterClient;
use App\Modules\Client\Application\Registration\RegisterClientCommand;
use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Client\Application\Security\ClientActorContextFactory;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Portfolio\PortfolioEntryType;
use App\Modules\Client\Domain\Portfolio\PortfolioStatus;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Presentation\Http\Requests\ClientIndexRequest;
use App\Modules\Client\Presentation\Http\Requests\StoreBankAccountRequest;
use App\Modules\Client\Presentation\Http\Requests\StoreClientRequest;
use App\Modules\Client\Presentation\Http\Requests\StorePortfolioEntryRequest;
use App\Modules\Client\Presentation\Http\Requests\UpdatePortfolioEntryRequest;
use App\Modules\Client\Presentation\Http\Resources\ClientAdministrativeDetailResource;
use App\Modules\Client\Presentation\Http\Resources\ClientBankAccountMaskedResource;
use App\Modules\Client\Presentation\Http\Resources\ClientDistributorDetailResource;
use App\Modules\Client\Presentation\Http\Resources\ClientListResource;
use App\Modules\Client\Presentation\Http\Resources\ClientPortfolioEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Adaptador HTTP delgado para los contratos públicos confirmados de M06. */
final class ClientController extends Controller
{
    public function __construct(
        private readonly ClientActorContextFactory $contexts,
        private readonly ClientQueryService $clients,
        private readonly RegisterClient $register,
        private readonly BankAccountQueryService $bankAccounts,
        private readonly ReplaceAuthorizedBankAccount $replaceBankAccount,
        private readonly PortfolioQueryService $portfolio,
        private readonly RecordPortfolioEntry $recordPortfolioEntry,
        private readonly UpdatePortfolioEntry $updatePortfolioEntry,
    ) {}

    public function index(ClientIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorizePolicy('viewAny');

        return ClientListResource::collection(
            $this->clients->paginate(
                $this->actor($request),
                $request->validated(),
                $this->requestId($request),
            ),
        );
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $this->authorizePolicy('create');

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        /** @var array<string, string|null> $address */
        $address = $data['address'];
        $result = $this->register->execute(new RegisterClientCommand(
            givenNames: (string) $data['given_names'],
            surnames: (string) $data['surnames'],
            curp: (string) $data['curp'],
            rfc: $this->nullableString($data['rfc'] ?? null),
            birthDate: $this->nullableString($data['birth_date'] ?? null),
            birthPlace: $this->nullableString($data['birth_place'] ?? null),
            birthState: $this->nullableString($data['birth_state'] ?? null),
            birthCity: $this->nullableString($data['birth_city'] ?? null),
            address: new AddressInput(
                street: (string) $address['street'],
                exteriorNumber: (string) $address['exterior_number'],
                interiorNumber: $this->nullableString($address['interior_number'] ?? null),
                neighborhood: (string) $address['neighborhood'],
                postalCode: (string) $address['postal_code'],
                municipality: (string) $address['municipality'],
                city: (string) $address['city'],
                state: (string) $address['state'],
            ),
            officialIdentificationMediaId: (string) $data['official_identification_media_id'],
            addressProofMediaId: (string) $data['address_proof_media_id'],
            bankAccount: (string) $data['bank_account'],
            idempotencyKey: (string) $data['idempotency_key'],
            requestId: $this->requestId($request),
            actor: $this->actor($request),
        ));

        return (new ClientDistributorDetailResource($result))
            ->response()
            ->setStatusCode($result->replayed ? 200 : 201);
    }

    public function show(Request $request, string $client): ClientAdministrativeDetailResource|ClientDistributorDetailResource
    {
        $this->authorizePolicy('viewAny');

        $actor = $this->actor($request);
        $model = $this->clients->findVisible($client, $actor, $this->requestId($request));

        return $actor->role === RoleCode::DISTRIBUTOR
            ? new ClientDistributorDetailResource($model)
            : new ClientAdministrativeDetailResource($model);
    }

    public function bankAccounts(Request $request, string $client): AnonymousResourceCollection
    {
        $this->authorizePolicy('viewAny');

        return ClientBankAccountMaskedResource::collection(
            $this->bankAccounts->forClient($client, $this->actor($request)),
        );
    }

    public function storeBankAccount(StoreBankAccountRequest $request, string $client): ClientBankAccountMaskedResource
    {
        $this->authorizePolicy('applyAuthorizedChange');

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $actor = $this->actor($request);
        $account = $this->replaceBankAccount->execute(new ApplyAuthorizedClientChangesCommand(
            authorizationId: (string) $data['authorization_id'],
            clientId: $client,
            authorizedFields: ['bank_account'],
            newValues: ['bank_account' => (string) $data['bank_account']],
            reason: (string) $data['reason'],
            operationId: (string) $data['operation_id'],
            expectedClientVersion: (int) $data['lock_version'],
            requestId: $this->requestId($request),
            cashier: $actor,
        ));

        return new ClientBankAccountMaskedResource($account);
    }

    public function portfolioEntries(Request $request, string $client): AnonymousResourceCollection
    {
        $this->authorizePolicy('viewPortfolio');

        return ClientPortfolioEntryResource::collection(
            $this->portfolio->paginate(
                $client,
                $this->actor($request),
                max(1, (int) $request->integer('per_page', 20)),
            ),
        );
    }

    public function storePortfolioEntry(
        StorePortfolioEntryRequest $request,
        string $client,
    ): JsonResponse {
        $this->authorizePolicy('writePortfolio');

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $entry = $this->recordPortfolioEntry->execute(new RecordPortfolioEntryCommand(
            clientId: $client,
            type: PortfolioEntryType::from((string) $data['entry_type']),
            amount: $this->nullableString($data['amount'] ?? null),
            status: PortfolioStatus::from((string) $data['informational_status']),
            occurredOn: (string) $data['occurred_on'],
            note: $this->nullableString($data['note'] ?? null),
            idempotencyKey: (string) $data['idempotency_key'],
            requestId: $this->requestId($request),
            actor: $this->actor($request),
        ));

        return (new ClientPortfolioEntryResource($entry))->response()->setStatusCode(201);
    }

    public function updatePortfolioEntry(
        UpdatePortfolioEntryRequest $request,
        string $client,
        string $entry,
    ): ClientPortfolioEntryResource {
        $this->authorizePolicy('writePortfolio');

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $updated = $this->updatePortfolioEntry->execute(new UpdatePortfolioEntryCommand(
            clientId: $client,
            entryId: $entry,
            status: isset($data['informational_status'])
                ? PortfolioStatus::from((string) $data['informational_status'])
                : null,
            note: array_key_exists('note', $data) ? $this->nullableString($data['note']) : null,
            expectedVersion: (int) $data['lock_version'],
            requestId: $this->requestId($request),
            actor: $this->actor($request),
        ));

        return new ClientPortfolioEntryResource($updated);
    }

    private function actor(Request $request): ClientActorContext
    {
        /** @var User $user */
        $user = $request->user();

        return $this->contexts->fromUser($user);
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function authorizePolicy(string $ability): void
    {
        if (! Gate::allows($ability, Client::class)) {
            throw ClientDomainException::authorizationDenied();
        }
    }
}
