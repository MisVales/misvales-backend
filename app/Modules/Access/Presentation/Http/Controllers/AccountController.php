<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Accounts\AccountLifecycleService;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Presentation\Http\Requests\CreateAccountRequest;
use App\Modules\Access\Presentation\Http\Requests\LifecycleRequest;
use App\Modules\Access\Presentation\Http\Requests\ResendInvitationRequest;
use Illuminate\Http\JsonResponse;

final class AccountController extends Controller
{
    public function __construct(private readonly AccountLifecycleService $accounts) {}

    public function store(CreateAccountRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $branch = $request->filled('branch_id') ? Branch::query()->where('public_id', $request->string('branch_id'))->firstOrFail() : null;
        $user = $this->accounts->createDirect($actor->loadMissing('role'), [
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role' => RoleCode::from($request->string('role')->toString()),
            'branch' => $branch,
        ], $request->string('authorization_token')->toString());

        return response()->json(['data' => $this->accountData($user)], 201);
    }

    public function disable(LifecycleRequest $request, User $account): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $user = $this->accounts->disableDirect($actor->loadMissing('role'), $account, $request->string('reason')->toString(), $request->string('reauth_token')->toString());

        return response()->json(['data' => $this->accountData($user)]);
    }

    public function reactivate(LifecycleRequest $request, User $account): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $user = $this->accounts->reactivateDirect($actor->loadMissing('role'), $account, $request->string('reason')->toString(), $request->boolean('compromise'), $request->string('reauth_token')->toString());

        return response()->json(['data' => $this->accountData($user)]);
    }

    public function recovery(LifecycleRequest $request, User $account): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $user = $this->accounts->recoveryDirect($actor->loadMissing('role'), $account, $request->string('reason')->toString(), $request->string('reauth_token')->toString());

        return response()->json(['data' => $this->accountData($user)]);
    }

    public function resend(ResendInvitationRequest $request, User $account): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $user = $this->accounts->resendInvitation($actor->loadMissing('role'), $account, $request->string('reauth_token')->toString());

        return response()->json(['data' => $this->accountData($user)]);
    }

    /** @return array<string, mixed> */
    private function accountData(User $user): array
    {
        $user->loadMissing(['role', 'branch']);

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'state' => $user->state->value,
            'role' => $user->role->code->value,
            'branch_id' => $user->branch?->public_id,
            'context_version' => $user->context_version,
        ];
    }
}
