<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Payment\Application\Queries\PaymentQueryService;
use App\Modules\Payment\Application\Security\PaymentActorContextFactory;
use App\Modules\Payment\Application\Security\PaymentAuthorizer;
use App\Modules\Payment\Application\Services\ChooseExcessAsCredit;
use App\Modules\Payment\Application\Services\UnavailablePaymentTransitions;
use App\Modules\Payment\Presentation\Http\Controllers\Concerns\ResolvesPaymentActor;
use App\Modules\Payment\Presentation\Http\Requests\CompleteRefundRequest;
use App\Modules\Payment\Presentation\Http\Requests\LinkMovementRequest;
use App\Modules\Payment\Presentation\Http\Requests\ReasonedPaymentRequest;
use App\Modules\Payment\Presentation\Http\Requests\ReceiveBankImportRequest;
use App\Modules\Payment\Presentation\Http\Requests\StoreClarificationRequest;
use App\Modules\Payment\Presentation\Http\Requests\StoreManualReconciliationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/** Expone comandos explícitos de M11 y delega sus reglas a Application. */
final class PaymentCommandController extends Controller
{
    use ResolvesPaymentActor;

    public function __construct(
        private readonly PaymentActorContextFactory $contexts,
        private readonly PaymentAuthorizer $authorizer,
        private readonly PaymentQueryService $queries,
        private readonly UnavailablePaymentTransitions $blocked,
        private readonly ChooseExcessAsCredit $chooseExcess,
    ) {}

    public function receiveBankImport(ReceiveBankImportRequest $request): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::BANK_IMPORTS_UPLOAD);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        return $this->blocked->receiveBankImport($file);
    }

    public function retryBankImport(ReasonedPaymentRequest $request, string $bankImport): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $import = $this->queries->bankImport($bankImport, $actor);
        $this->authorizer->assertAnyPermission($actor, [
            PermissionCode::BANK_IMPORTS_RETRY_BRANCH,
            PermissionCode::BANK_IMPORTS_RETRY_GLOBAL,
        ]);
        $this->authorizer->assertBranch($actor, (int) $import->branch_id);

        return $this->blocked->relationDependent();
    }

    public function createClarification(StoreClarificationRequest $request): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::CLARIFICATIONS_CREATE_OWN);
        $evidence = $request->file('evidence');
        if (! $evidence instanceof UploadedFile) {
            abort(422);
        }

        return $this->blocked->clarification($evidence);
    }

    public function linkMovement(LinkMovementRequest $request, string $clarification): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::CLARIFICATIONS_REVIEW_BRANCH);
        $this->queries->clarification($clarification, $actor);

        return $this->blocked->relationDependent();
    }

    public function rejectClarification(ReasonedPaymentRequest $request, string $clarification): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::CLARIFICATIONS_REVIEW_BRANCH);
        $this->queries->clarification($clarification, $actor);

        return $this->blocked->relationDependent();
    }

    public function requestManualReconciliation(StoreManualReconciliationRequest $request): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::MANUAL_RECONCILIATIONS_REQUEST);

        return $this->blocked->relationDependent();
    }

    public function authorizeManual(ReasonedPaymentRequest $request, string $manualReconciliation): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertAnyPermission($actor, [
            PermissionCode::MANUAL_RECONCILIATIONS_AUTHORIZE_ASSIGNED,
            PermissionCode::MANUAL_RECONCILIATIONS_AUTHORIZE_BRANCH,
            PermissionCode::MANUAL_RECONCILIATIONS_AUTHORIZE_GLOBAL,
        ]);
        $this->queries->manualReconciliation($manualReconciliation, $actor);

        return $this->blocked->relationDependent();
    }

    public function rejectManual(ReasonedPaymentRequest $request, string $manualReconciliation): JsonResponse
    {
        return $this->authorizeManual($request, $manualReconciliation);
    }

    public function applyManual(ReasonedPaymentRequest $request, string $manualReconciliation): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::MANUAL_RECONCILIATIONS_APPLY);
        $this->queries->manualReconciliation($manualReconciliation, $actor);

        return $this->blocked->relationDependent();
    }

    public function chooseCredit(ReasonedPaymentRequest $request, string $excessBalance): JsonResponse
    {
        $result = $this->chooseExcess->execute(
            $excessBalance,
            (int) $request->validated('lock_version'),
            $this->paymentActor($request),
            (string) $request->header('Idempotency-Key'),
            $this->paymentRequestId($request),
        );

        return response()->json(['data' => $result]);
    }

    public function requestRefund(ReasonedPaymentRequest $request, string $excessBalance): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::EXCESS_BALANCES_DECIDE_OWN);
        $this->queries->excessBalance($excessBalance, $actor);

        return $this->blocked->refund('UNDEFINED', []);
    }

    public function authorizeRefund(ReasonedPaymentRequest $request, string $refund): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertAnyPermission($actor, [
            PermissionCode::REFUNDS_AUTHORIZE_BRANCH,
            PermissionCode::REFUNDS_AUTHORIZE_GLOBAL,
        ]);
        $this->queries->refundRequest($refund, $actor);

        return $this->blocked->refund('UNDEFINED', []);
    }

    public function rejectRefund(ReasonedPaymentRequest $request, string $refund): JsonResponse
    {
        return $this->authorizeRefund($request, $refund);
    }

    public function completeRefund(CompleteRefundRequest $request, string $refund): JsonResponse
    {
        $actor = $this->paymentActor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::REFUNDS_COMPLETE);
        $this->queries->refundRequest($refund, $actor);

        return $this->blocked->refund((string) $request->validated('method'), $request->validated());
    }

    protected function paymentContexts(): PaymentActorContextFactory
    {
        return $this->contexts;
    }
}
