<?php

namespace App\Modules\Organization\Application\Personnel\Queries;

final readonly class PersonnelView
{
    public function __construct(
        public string $assignmentId,
        public string $userId,
        public string $userName,
        public string $userEmail,
        public string $userState,
        public string $roleId,
        public string $roleCode,
        public string $roleName,
        public ?string $branchId,
        public string $scope,
        public string $assignmentStatus,
        public string $assignedAt,
        public ?string $assignmentReason,
        public ?string $revokedAt,
        public ?string $revocationReason,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'assignment_id' => $this->assignmentId,
            'user' => [
                'id' => $this->userId,
                'name' => $this->userName,
                'email' => $this->userEmail,
                'state' => $this->userState,
            ],
            'role' => [
                'id' => $this->roleId,
                'code' => $this->roleCode,
                'name' => $this->roleName,
            ],
            'branch_id' => $this->branchId,
            'scope' => $this->scope,
            'assignment_status' => $this->assignmentStatus,
            'assigned_at' => $this->assignedAt,
            'assignment_reason' => $this->assignmentReason,
            'revoked_at' => $this->revokedAt,
            'revocation_reason' => $this->revocationReason,
        ];
    }
}
