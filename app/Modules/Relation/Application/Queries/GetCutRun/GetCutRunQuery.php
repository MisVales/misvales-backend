<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Queries\GetCutRun;

use App\Modules\Relation\Infrastructure\Persistence\Models\CutRun;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class GetCutRunQuery
{
    public function handle(string $cutRunId): CutRun
    {
        return CutRun::with('distributors')->findOrFail($cutRunId);
    }
}
