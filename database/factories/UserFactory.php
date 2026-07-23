<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'state' => AccountState::ACTIVE,
            'context_version' => 1,
            'role_id' => fn () => $this->role(RoleCode::DISTRIBUTOR)->id,
            'branch_id' => Branch::factory(),
            'remember_token' => Str::random(10),
        ];
    }

    public function generalManager(): static
    {
        return $this->forRole(RoleCode::GENERAL_MANAGER);
    }

    public function sucursalManager(): static
    {
        return $this->forRole(RoleCode::SUCURSAL_MANAGER);
    }

    public function coordinator(): static
    {
        return $this->forRole(RoleCode::COORDINATOR);
    }

    public function verifier(): static
    {
        return $this->forRole(RoleCode::VERIFIER);
    }

    public function administrator(): static
    {
        return $this->forRole(RoleCode::ADMINISTRATOR);
    }

    public function distributor(): static
    {
        return $this->forRole(RoleCode::DISTRIBUTOR);
    }

    public function cashier(): static
    {
        return $this->forRole(RoleCode::CASHIER);
    }

    private function forRole(RoleCode $code): static
    {
        return $this->state(function () use ($code): array {
            $role = $this->role($code);

            return [
                'role_id' => $role->id,
                'branch_id' => $code->isGlobal() ? null : Branch::factory(),
            ];
        });
    }

    private function role(RoleCode $code): Role
    {
        return Role::query()->firstOrCreate(
            ['code' => $code->value],
            ['name' => str($code->value)->replace('_', ' ')->title(), 'scope' => $code->isGlobal() ? 'GLOBAL' : 'BRANCH'],
        );
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
