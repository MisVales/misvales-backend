<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Commands\GenerateVoucher;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Credit\Application\Contracts\CreditVoucherGateway;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\ValueObjects\Money as CreditMoney;
use App\Modules\RiskDelinquency\Application\Contracts\CanDistributorIssueVoucher;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\Voucher\Application\Contracts\ClientVoucherGateway;
use App\Modules\Voucher\Application\Contracts\DistributorVoucherGateway;
use App\Modules\Voucher\Application\Contracts\VoucherConfigurationGateway;
use App\Modules\Voucher\Application\Contracts\VoucherGenerationRepository;
use App\Modules\Voucher\Application\DTOs\GeneratedVoucherData;
use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Application\Security\VoucherActorContextFactory;
use App\Modules\Voucher\Application\Services\IdempotencyService;
use App\Modules\Voucher\Application\Services\VoucherDataBuilder;
use App\Modules\Voucher\Application\Services\VoucherRecorder;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Domain\Services\VoucherCalculator;
use App\Modules\Voucher\Domain\Services\VoucherTypeResolver;
use App\Modules\Voucher\Domain\ValueObjects\Money;
use App\Modules\Voucher\Domain\ValueObjects\VoucherFolio;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Caso de uso transaccional completo de generación de M08. */
final readonly class Handler
{
    public function __construct(
        private ClientVoucherGateway $clients,
        private DistributorVoucherGateway $distributors,
        private VoucherConfigurationGateway $configuration,
        private CreditVoucherGateway $credit,
        private CanDistributorIssueVoucher $delinquency,
        private VoucherTypeResolver $types,
        private VoucherCalculator $calculator,
        private VoucherGenerationRepository $vouchers,
        private VoucherActorContextFactory $contexts,
        private IdempotencyService $idempotency,
        private VoucherRecorder $recorder,
        private VoucherDataBuilder $data,
    ) {}

    public function handle(Command $command): Result
    {
        $actor = $this->contexts->fromUser($command->actor);
        $this->recorder->audit(
            'VOUCHER_GENERATION_ATTEMPTED',
            'ATTEMPTED',
            null,
            $actor,
            $command->metadata,
            ['client_id' => $command->clientId, 'product_id' => $command->productId],
        );

        try {
            if (
                $actor->role !== RoleCode::DISTRIBUTOR
                || ! $actor->hasPermission(PermissionCode::VOUCHERS_GENERATE->value)
            ) {
                throw VoucherDomainException::generationPermissionDenied();
            }

            return DB::transaction(fn (): Result => $this->generate($command, $actor), 3);
        } catch (RiskDelinquencyException $exception) {
            $mapped = VoucherDomainException::rule(
                $exception->errorCode() === 'DISTRIBUTOR_DELINQUENT'
                    ? 'DISTRIBUTOR_DELINQUENT'
                    : 'RESOURCE_VERSION_CONFLICT',
                $exception->getMessage(),
                $exception->httpStatus(),
            );
            $this->recordBlocked($command, $actor, $mapped);
            throw $mapped;
        } catch (CreditRuleViolation $exception) {
            $mapped = $this->mapCredit($exception);
            $this->recordBlocked($command, $actor, $mapped);
            throw $mapped;
        } catch (ConfigurationException) {
            $mapped = VoucherDomainException::productUnavailable();
            $this->recordBlocked($command, $actor, $mapped);
            throw $mapped;
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'vouchers_one_prevale_per_client')) {
                $mapped = VoucherDomainException::prevaleConflict($exception);
                $this->recordBlocked($command, $actor, $mapped);
                throw $mapped;
            }
            if (str_contains($exception->getMessage(), 'vouchers_folio_unique')) {
                $mapped = VoucherDomainException::rule(
                    'RESOURCE_VERSION_CONFLICT',
                    'No fue posible reservar un folio único para la operación.',
                );
                $this->recordBlocked($command, $actor, $mapped);
                throw $mapped;
            }
            throw $exception;
        } catch (VoucherDomainException $exception) {
            $this->recordBlocked($command, $actor, $exception);
            throw $exception;
        }
    }

    private function generate(
        Command $command,
        VoucherActorContext $actor,
    ): Result {
        $reservation = $this->idempotency->reserve(
            $actor->userId,
            'GENERATE_VOUCHER',
            $command->metadata->idempotencyKey,
            ['client_id' => $command->clientId, 'product_id' => $command->productId],
        );
        if ($reservation->completed_at !== null) {
            $payload = $reservation->response_payload ?? [];
            $this->recorder->audit(
                'VOUCHER_GENERATION_IDEMPOTENT_REPLAY',
                'IDEMPOTENT_REPLAY',
                is_string($payload['id'] ?? null) ? $payload['id'] : null,
                $actor,
                $command->metadata,
            );

            return new Result($payload, true);
        }

        $client = $this->clients->lockAssigned($command->clientId, $command->actor);
        $distributor = $this->distributors->lockAuthenticated($command->actor);
        $this->delinquency->assertAllowed($distributor->userId);
        $at = CarbonImmutable::now('UTC');
        $product = $this->configuration->product($command->productId, $at);
        $category = $this->configuration->category(
            $distributor->categoryId,
            $distributor->categoryVersionId,
            $at,
        );
        $credit = $this->credit->lockedEligibility(
            $distributor->userId,
            new CreditMoney($product->capital->databaseValue()),
        );
        if ($credit->boundVoucherId !== null) {
            throw VoucherDomainException::creditRestrictionLinked();
        }
        if (! $credit->eligible) {
            throw $product->capital->compare(new Money($credit->availableBalance->databaseValue())) > 0
                ? VoucherDomainException::creditInsufficient()
                : VoucherDomainException::creditRangeInvalid();
        }
        if ($credit->creditLineId === null) {
            throw VoucherDomainException::creditInsufficient();
        }

        $type = $this->types->resolve($client->id, $client->wasTransferred);
        $calculation = $this->calculator->calculate(
            $product->capital,
            $product->commissionRate,
            $product->interestRate,
            $product->fortnights,
            $product->insurance,
            $category->profitRate,
        );
        $voucher = $this->vouchers->create(new GeneratedVoucherData(
            id: (string) Str::uuid(),
            folio: VoucherFolio::generate()->value(),
            type: $type,
            distributor: $distributor,
            client: $client,
            product: $product,
            category: $category,
            credit: $credit,
            generatedBy: $actor->userId,
            calculation: $calculation,
        ));
        $this->credit->bindRestriction(
            $distributor->userId,
            $voucher->id,
            new CreditMoney($product->capital->databaseValue()),
            $actor->userId,
        );

        $creditContext = [
            'available_before_fulfillment' => $credit->availableBalance->format(),
            'special_rule_applied' => $credit->restrictionRange !== null,
            'restriction_id' => $credit->restrictionId,
            'minimum_allowed' => $credit->restrictionRange?->lower->format(),
            'maximum_allowed' => $credit->restrictionRange?->upper->format(),
        ];
        $this->recorder->operation(
            $voucher->id,
            'VOUCHER_GENERATED',
            null,
            'GENERADO',
            $actor,
            $command->metadata,
            [
                'type' => $type->value,
                'client_id' => $client->id,
                'distributor_id' => $distributor->id,
                'branch_id' => $distributor->branchPublicId,
                'product_version_id' => $product->versionId,
                'category_version_id' => $category->versionId,
                'credit_validation' => $creditContext,
                'snapshot_created' => true,
                'installments_created' => $calculation->payments,
            ],
        );
        $this->recorder->event('VoucherGenerated', $voucher->id, 'voucher-generated:'.$voucher->id, [
            'event_id' => (string) Str::uuid(),
            'voucher_id' => $voucher->id,
            'folio' => $voucher->folio,
            'type' => $type->value,
            'distributor_id' => $distributor->id,
            'client_id' => $client->id,
            'branch_id' => $distributor->branchPublicId,
            'capital' => $product->capital->format(),
            'previous_status' => null,
            'new_status' => 'GENERADO',
            'special_rule_applied' => $credit->restrictionRange !== null,
            'actor_id' => $actor->publicId,
            'business_date' => now('America/Monterrey')->toDateString(),
            'occurred_at' => now('UTC')->toIso8601String(),
            'request_id' => $command->metadata->requestId,
            'trace_id' => $command->metadata->requestId,
        ]);
        $payload = $this->data->build($voucher);
        $this->idempotency->complete($reservation, 201, $payload);

        return new Result($payload, false);
    }

    private function mapCredit(CreditRuleViolation $exception): VoucherDomainException
    {
        return match ($exception->errorCode()) {
            'CREDIT_RESTRICTION_ALREADY_BOUND' => VoucherDomainException::creditRestrictionLinked(),
            'CREDIT_50_PERCENT_NO_ADMISSIBLE_AMOUNT' => VoucherDomainException::creditRangeInvalid(),
            'AUTH_SCOPE_DENIED' => VoucherDomainException::distributorInactive(),
            default => VoucherDomainException::rule(
                $exception->errorCode() === 'CREDIT_LINE_NOT_FOUND'
                    ? 'CREDIT_INSUFFICIENT'
                    : $exception->errorCode(),
                $exception->getMessage(),
                $exception->statusCode(),
            ),
        };
    }

    private function recordBlocked(
        Command $command,
        VoucherActorContext $actor,
        VoucherDomainException $exception,
    ): void {
        try {
            DB::transaction(function () use ($command, $actor, $exception): void {
                $context = [
                    'rule' => $exception->errorCode(),
                    'client_id' => $command->clientId,
                    'product_id' => $command->productId,
                ];
                $this->recorder->audit(
                    'VOUCHER_GENERATION_BLOCKED',
                    'DENIED',
                    null,
                    $actor,
                    $command->metadata,
                    $context,
                    $exception->errorCode(),
                );
                $this->recorder->event(
                    'VoucherGenerationBlocked',
                    (string) Str::uuid(),
                    'voucher-generation-blocked:'.hash(
                        'sha256',
                        $actor->userId.'|'.$command->metadata->requestId.'|'.$exception->errorCode(),
                    ),
                    [
                        'reason_code' => $exception->errorCode(),
                        'rule' => $exception->errorCode(),
                        'distributor_user_id' => $actor->publicId,
                        'client_id' => $command->clientId,
                        'product_id' => $command->productId,
                        'branch_id' => $actor->branchPublicId,
                        'actor_id' => $actor->publicId,
                        'occurred_at' => now('UTC')->toIso8601String(),
                        'request_id' => $command->metadata->requestId,
                    ],
                );
            });
        } catch (Throwable) {
            // Un fallo secundario de auditoría nunca reemplaza el error estable.
        }
    }
}
