<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Voucher\Application\DTOs\OperationMetadata;
use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Application\Security\VoucherActorContextFactory;
use App\Modules\Voucher\Application\Services\ModificationWorkflowService;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;
use App\Modules\Voucher\Presentation\Http\Requests\ApplyModificationRequest;
use App\Modules\Voucher\Presentation\Http\Requests\ModificationDecisionRequest;
use App\Modules\Voucher\Presentation\Http\Requests\ModificationRequestIndexRequest;
use App\Modules\Voucher\Presentation\Http\Resources\ModificationRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class ModificationRequestController extends Controller
{
    public function __construct(
        private readonly ModificationWorkflowService $workflow,
        private readonly VoucherActorContextFactory $contexts,
    ) {}

    public function index(ModificationRequestIndexRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', DataChangeRequestModel::class);

        return ModificationRequestResource::collection(
            $this->workflow->list($this->actor($request), $request->validated()),
        );
    }

    public function show(Request $request, string $modificationRequest): ModificationRequestResource
    {
        Gate::authorize('viewAny', DataChangeRequestModel::class);

        return new ModificationRequestResource(
            $this->workflow->get($modificationRequest, $this->actor($request)),
        );
    }

    public function authorizeRequest(
        ModificationDecisionRequest $request,
        string $modificationRequest,
    ): ModificationRequestResource {
        Gate::authorize('decide', DataChangeRequestModel::class);

        return new ModificationRequestResource($this->workflow->authorize(
            $modificationRequest,
            (int) $request->validated('lock_version'),
            (string) $request->validated('decision_reason'),
            (string) $request->validated('reauthentication_token'),
            $this->user($request),
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    public function rejectRequest(
        ModificationDecisionRequest $request,
        string $modificationRequest,
    ): ModificationRequestResource {
        Gate::authorize('decide', DataChangeRequestModel::class);

        return new ModificationRequestResource($this->workflow->reject(
            $modificationRequest,
            (int) $request->validated('lock_version'),
            (string) $request->validated('decision_reason'),
            (string) $request->validated('reauthentication_token'),
            $this->user($request),
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    public function apply(
        ApplyModificationRequest $request,
        string $modificationRequest,
    ): ModificationRequestResource {
        Gate::authorize('apply', DataChangeRequestModel::class);

        return new ModificationRequestResource($this->workflow->apply(
            $modificationRequest,
            (string) $request->validated('token'),
            $request->validated('changes'),
            (int) $request->validated('lock_version'),
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw VoucherDomainException::scopeDenied();
        }

        return $user;
    }

    private function actor(Request $request): VoucherActorContext
    {
        return $this->contexts->fromUser($this->user($request));
    }

    private function metadata(Request $request): OperationMetadata
    {
        return new OperationMetadata(
            requestId: (string) $request->header('X-Request-Id'),
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
