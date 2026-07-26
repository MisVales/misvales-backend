<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Application\Queries\PaymentQueryService;
use App\Modules\Payment\Application\Security\PaymentActorContextFactory;
use App\Modules\Payment\Presentation\Http\Controllers\Concerns\ResolvesPaymentActor;
use App\Modules\Payment\Presentation\Http\Requests\PaymentIndexRequest;
use App\Modules\Payment\Presentation\Http\Resources\BankImportResource;
use App\Modules\Payment\Presentation\Http\Resources\BankMovementResource;
use App\Modules\Payment\Presentation\Http\Resources\ClarificationResource;
use App\Modules\Payment\Presentation\Http\Resources\ExcessBalanceResource;
use App\Modules\Payment\Presentation\Http\Resources\ManualReconciliationResource;
use App\Modules\Payment\Presentation\Http\Resources\PaymentAllocationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Expone consultas paginadas y filtradas por alcance de M11. */
final class PaymentReadController extends Controller
{
    use ResolvesPaymentActor;

    public function __construct(
        private readonly PaymentQueryService $queries,
        private readonly PaymentActorContextFactory $contexts,
    ) {}

    public function bankImports(PaymentIndexRequest $request): AnonymousResourceCollection
    {
        return BankImportResource::collection($this->queries->bankImports(
            $this->paymentActor($request),
            $request->validated(),
        ));
    }

    public function bankImport(Request $request, string $bankImport): BankImportResource
    {
        return new BankImportResource($this->queries->bankImport($bankImport, $this->paymentActor($request)));
    }

    public function importMovements(
        PaymentIndexRequest $request,
        string $bankImport,
    ): AnonymousResourceCollection {
        $this->queries->bankImport($bankImport, $this->paymentActor($request));

        return BankMovementResource::collection($this->queries->bankMovements(
            $this->paymentActor($request),
            $request->validated(),
            $bankImport,
        ));
    }

    public function bankMovements(PaymentIndexRequest $request): AnonymousResourceCollection
    {
        return BankMovementResource::collection($this->queries->bankMovements(
            $this->paymentActor($request),
            $request->validated(),
        ));
    }

    public function bankMovement(Request $request, string $bankMovement): BankMovementResource
    {
        return new BankMovementResource($this->queries->bankMovement($bankMovement, $this->paymentActor($request)));
    }

    public function relationPayments(
        PaymentIndexRequest $request,
        string $relation,
    ): AnonymousResourceCollection {
        return PaymentAllocationResource::collection($this->queries->relationPayments(
            $relation,
            $this->paymentActor($request),
            (int) ($request->validated('per_page') ?? 20),
        ));
    }

    public function allocation(Request $request, string $paymentAllocation): PaymentAllocationResource
    {
        return new PaymentAllocationResource($this->queries->allocation(
            $paymentAllocation,
            $this->paymentActor($request),
        ));
    }

    public function clarifications(PaymentIndexRequest $request): AnonymousResourceCollection
    {
        return ClarificationResource::collection($this->queries->clarifications(
            $this->paymentActor($request),
            $request->validated(),
        ));
    }

    public function clarification(Request $request, string $clarification): ClarificationResource
    {
        return new ClarificationResource($this->queries->clarification(
            $clarification,
            $this->paymentActor($request),
        ));
    }

    public function manualReconciliations(PaymentIndexRequest $request): AnonymousResourceCollection
    {
        return ManualReconciliationResource::collection($this->queries->manualReconciliations(
            $this->paymentActor($request),
            $request->validated(),
        ));
    }

    public function manualReconciliation(Request $request, string $manualReconciliation): ManualReconciliationResource
    {
        return new ManualReconciliationResource($this->queries->manualReconciliation(
            $manualReconciliation,
            $this->paymentActor($request),
        ));
    }

    public function excessBalances(PaymentIndexRequest $request): AnonymousResourceCollection
    {
        return ExcessBalanceResource::collection($this->queries->excessBalances(
            $this->paymentActor($request),
            $request->validated(),
        ));
    }

    public function excessBalance(Request $request, string $excessBalance): ExcessBalanceResource
    {
        return new ExcessBalanceResource($this->queries->excessBalance(
            $excessBalance,
            $this->paymentActor($request),
        ));
    }

    protected function paymentContexts(): PaymentActorContextFactory
    {
        return $this->contexts;
    }
}
