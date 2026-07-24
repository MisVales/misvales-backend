<?php

namespace App\Modules\Access\Domain\Context;

final readonly class EffectiveContext implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $role
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $hierarchy
     * @param  array<string, mixed>  $experience
     * @param  array<string, mixed>  $session
     */
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

    /** @return array<string, mixed> */
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
