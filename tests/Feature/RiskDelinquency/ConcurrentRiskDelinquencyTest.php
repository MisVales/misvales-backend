<?php

declare(strict_types=1);

namespace Tests\Feature\RiskDelinquency;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Modules\RiskDelinquency\Application\DTOs\RelationPostDueEvaluation;
use App\Modules\RiskDelinquency\Application\Services\ConsumeRelationPostDueEvaluation;
use App\Modules\RiskDelinquency\Domain\Enums\FinancialResult;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Pruebas reales de bloqueo PostgreSQL; Windows las omite por falta de pcntl. */
final class ConcurrentRiskDelinquencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_two_consumers_count_the_same_relation_once(): void
    {
        $branch = Branch::query()->firstOrFail();
        $role = Role::query()->where('code', RoleCode::DISTRIBUTOR->value)->firstOrFail();
        $distributor = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'state' => AccountState::ACTIVE,
        ]);
        $due = CarbonImmutable::parse('2026-06-01 08:00:00', 'America/Monterrey');
        $input = new RelationPostDueEvaluation(
            relationId: (string) Str::uuid(),
            distributorId: $distributor->id,
            branchId: $branch->id,
            cutId: 'CUT-CONCURRENT',
            cutAt: $due->subWeek(),
            dueAt: $due,
            result: FinancialResult::NO_PAGO,
            overdueBalance: '100.0000',
            evaluatedAt: $due->addDay(),
            sourceVersion: 'v1',
            sourceReady: true,
        );

        $results = $this->runConcurrently(
            fn (): string => app(ConsumeRelationPostDueEvaluation::class)->consume($input)->id,
        );

        self::assertCount(2, $results);
        self::assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('relation_risk_evaluations', 1);
        $this->assertDatabaseCount('risk_alerts', 1);
    }

    /** @param callable(): string $operation
     * @return list<string>
     */
    private function runConcurrently(callable $operation): array
    {
        $children = [];
        foreach ([1, 2] as $worker) {
            $resultFile = tempnam(sys_get_temp_dir(), 'risk-concurrency-');
            [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($parentSocket);
                fread($childSocket, 1);
                DB::disconnect();
                file_put_contents($resultFile, $operation());
                fclose($childSocket);
                exit(0);
            }
            fclose($childSocket);
            $children[] = [$pid, $parentSocket, $resultFile, $worker];
        }
        foreach ($children as [, $socket]) {
            fwrite($socket, '1');
            fclose($socket);
        }
        $results = [];
        foreach ($children as [$pid, , $resultFile]) {
            pcntl_waitpid($pid, $status);
            $results[] = (string) file_get_contents($resultFile);
            unlink($resultFile);
        }

        return $results;
    }
}
