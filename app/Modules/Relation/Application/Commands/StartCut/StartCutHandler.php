<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Commands\StartCut;

use App\Modules\Relation\Application\Contracts\ConfigurationSnapshotProvider;
use App\Modules\Relation\Domain\Enums\CutRunStatus;
use App\Modules\Relation\Domain\Events\CutStarted;
use App\Modules\Relation\Infrastructure\Persistence\Models\CutRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StartCutHandler
{
    public function __construct(
        private readonly ConfigurationSnapshotProvider $snapshotProvider
    ) {}

    public function handle(StartCutCommand $command): CutRun
    {
        return DB::transaction(function () use ($command) {
            $existingRun = CutRun::where('cut_date', $command->operativeDate->format('Y-m-d'))
                ->lockForUpdate()
                ->first();

            if ($existingRun) {
                if ($existingRun->status === CutRunStatus::COMPLETADA) {
                    throw new RuntimeException('CUT_ALREADY_COMPLETED');
                }

                return $existingRun;
            }

            $snapshot = $this->snapshotProvider->resolveSnapshot($command->operativeDate);

            $cutRun = CutRun::create([
                'cut_date' => $command->operativeDate->format('Y-m-d'),
                'business_timezone' => 'America/Monterrey',
                'status' => CutRunStatus::PENDIENTE,
                'configuration_snapshot' => $snapshot,
                'started_at' => now(),
                'trigger_type' => $command->triggerType,
                'triggered_by' => $command->triggeredBy,
            ]);

            event(new CutStarted(
                $cutRun->id,
                $cutRun->cut_date->format('Y-m-d'),
                $snapshot,
                $command->triggerType
            ));

            return $cutRun;
        });
    }
}
