<?php
namespace App\Modules\Access\Domain\Context;

final readonly class EffectiveContext implements \JsonSerializable
{
    public function __construct(
        public array $user,
        public array $role,
        public array $scope,
        public array $permissions,
        public array $hierarchy,
        public array $experience,
        public array $session,
        public int $contextVersion
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'user' => $this->user,
            'role' => $this->role,
            'scope' => $this->scope,
            'permissions' => $this->permissions,
            'hierarchy' => $this->hierarchy,
            'experience' => $this->experience,
            'session' => $this->session,
            'contextVersion' => $this->contextVersion,
        ];
    }
}
