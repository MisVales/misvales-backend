<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\ExcessBalance\Application\Contracts\PrivateEvidencePort;
use App\Modules\ExcessBalance\Application\DTOs\OperationContext;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use Illuminate\Support\Facades\DB;

final readonly class RefundEvidenceService
{
    public function __construct(
        private ExcessQueryService $queries,
        private PrivateEvidencePort $evidence,
        private ExcessRecorder $recorder,
    ) {}

    public function temporaryAccess(User $actor, string $requestId, OperationContext $context): string
    {
        $actor->loadMissing('role.permissions');
        $allowed = $actor->role->permissions
            ->where('is_active', true)
            ->contains(
                fn (mixed $permission): bool => $permission->code === PermissionCode::REFUND_EVIDENCE_VIEW,
            );
        if (! $allowed) {
            throw ExcessBalanceException::authorizationDenied();
        }
        $request = $this->queries->refund($actor, $requestId);
        if ($request->evidence_media_file_id === null) {
            throw ExcessBalanceException::notFound();
        }
        $file = DB::table('excess_evidence_files')->where('id', $request->evidence_media_file_id)->first();
        if ($file === null) {
            throw ExcessBalanceException::notFound();
        }
        $url = $this->evidence->temporaryAccess((string) $file->storage_file_id, $actor->id);
        $this->recorder->audit(
            'REFUND_EVIDENCE_ACCESSED',
            'SUCCESS',
            'refund_requests',
            $request->id,
            $context,
            metadata: ['evidence_file_id' => $file->id],
        );

        return $url;
    }
}
