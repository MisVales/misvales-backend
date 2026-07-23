<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Accounts\AccountLifecycleService;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Accounts\AccountRequestState;
use App\Modules\Access\Domain\Accounts\AccountRequestType;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountRequest;
use App\Modules\Access\Presentation\Http\Requests\BranchAccountRequest;
use App\Modules\Access\Presentation\Http\Requests\DecisionRequest;
use App\Modules\Access\Presentation\Http\Requests\LifecycleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountRequestController extends Controller
{
    public function __construct(private readonly AccountLifecycleService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $actor->loadMissing('role');
        $query = AccountRequest::query()->latest();
        if ($actor->role->code === RoleCode::SUCURSAL_MANAGER) {
            $query->where('branch_id', $actor->branch_id);
        } elseif ($actor->role->code !== RoleCode::GENERAL_MANAGER) {
            throw new AccessRuleViolation('La cuenta no puede consultar solicitudes.', 403);
        }

        return response()->json(['data' => $query->paginate(25)]);
    }

    public function store(BranchAccountRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $accountRequest = $this->accounts->requestBranchCreation($actor->loadMissing('role'), [
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role' => RoleCode::from($request->string('role')->toString()),
            'reason' => $request->string('reason')->toString(),
            'idempotency_key' => $request->string('idempotency_key')->toString(),
        ], $request->string('reauth_token')->toString());

        return response()->json(['data' => $accountRequest], 202);
    }

    public function approve(DecisionRequest $request, AccountRequest $accountRequest): JsonResponse
    {
        return $this->decision($request, $accountRequest, AccountRequestState::APPROVED);
    }

    public function reject(DecisionRequest $request, AccountRequest $accountRequest): JsonResponse
    {
        return $this->decision($request, $accountRequest, AccountRequestState::REJECTED);
    }

    public function disableRequest(LifecycleRequest $request, User $account): JsonResponse
    {
        return $this->lifecycleRequest($request, $account, AccountRequestType::DISABLE);
    }

    public function reactivateRequest(LifecycleRequest $request, User $account): JsonResponse
    {
        return $this->lifecycleRequest($request, $account, AccountRequestType::REACTIVATE);
    }

    public function recoveryRequest(LifecycleRequest $request, User $account): JsonResponse
    {
        return $this->lifecycleRequest($request, $account, AccountRequestType::RECOVERY);
    }

    private function decision(DecisionRequest $request, AccountRequest $accountRequest, AccountRequestState $decision): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $result = $this->accounts->decide($actor->loadMissing('role'), $accountRequest, $decision, $request->string('reason')->toString(), $request->string('reauth_token')->toString());

        return response()->json(['data' => $result]);
    }

    private function lifecycleRequest(LifecycleRequest $request, User $account, AccountRequestType $type): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $result = $this->accounts->requestLifecycleAction(
            $actor->loadMissing('role'),
            $account,
            $type,
            $request->string('reason')->toString(),
            $request->string('idempotency_key')->toString(),
            $request->string('reauth_token')->toString(),
        );

        return response()->json(['data' => $result], 202);
    }
}
