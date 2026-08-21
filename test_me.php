<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'pepe@gmail.com')->first();
$assignmentRules = app(\App\Modules\Organization\Domain\Assignments\Services\OrganizationAssignmentRules::class);

$user->load(['roleScopes' => function ($query) {
    $query->where('status', 'ACTIVE')
        ->whereNull('revoked_at')
        ->with(['role' => function ($roleQuery) {
            $roleQuery->with('permissions');
        }]);
}]);

$scopes = [];
$effectivePermissions = [];

foreach ($user->roleScopes as $scope) {
    if (! $scope->role) continue;
    try {
        $organizationScope = \App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope::fromString($scope->scope_type);
        $assignmentRules->assertRoleAllowsScope($scope->role->code, $organizationScope);
    } catch (\Exception $e) {
        echo "Error on scope {$scope->scope_type}: " . $e->getMessage() . "\n";
        continue;
    }

    if (($organizationScope === \App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope::BRANCH && $scope->branch_id === null)
        || ($organizationScope === \App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope::GLOBAL && $scope->branch_id !== null)
        || ($organizationScope === \App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope::DISTRIBUTOR
            && ($scope->branch_id === null || $scope->scope_id === null))) {
        echo "Failed condition for {$scope->scope_type}: branch_id={$scope->branch_id}, scope_id={$scope->scope_id}\n";
        continue;
    }

    $rolePermissions = $scope->role->permissions->pluck('code')->toArray();
    $scopes[] = [
        'role' => $scope->role->code,
        'scope_type' => $scope->scope_type,
    ];
    $effectivePermissions = array_merge($effectivePermissions, $rolePermissions);
}

echo json_encode([
    'scopes' => $scopes,
    'effective_permissions_count' => count(array_unique($effectivePermissions)),
], JSON_PRETTY_PRINT);
