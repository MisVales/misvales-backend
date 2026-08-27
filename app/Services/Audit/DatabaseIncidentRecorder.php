<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\RuntimeDiagnostics;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

final class DatabaseIncidentRecorder
{
    public function record(Request $request, QueryException $exception): void
    {
        $incident = [
            'id' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
            'actor_id' => $this->safe(fn () => $request->user()?->id),
            'actor_role' => $this->safe(fn () => $request->user()?->roles()->value('code')),
            'branch_id' => $this->safe(fn () => $request->user()?->branch_id),
            'request_id' => $request->attributes->get('request_id'),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'trace_id' => $request->attributes->get('trace_id'),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500),
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'sql_state' => (string) $exception->getCode(),
            'driver_code' => $exception->errorInfo[1] ?? null,
            'technical_message' => RuntimeDiagnostics::exception($exception)['message'],
        ];

        $path = $this->path();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, json_encode($incident, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function importPending(): int
    {
        $path = $this->path();
        if (! is_file($path)) {
            return 0;
        }

        $handle = fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            return 0;
        }

        try {
            rewind($handle);
            $pending = [];
            $imported = 0;
            while (($line = fgets($handle)) !== false) {
                $incident = json_decode(trim($line), true);
                if (! is_array($incident) || empty($incident['id'])) {
                    continue;
                }

                try {
                    AuditLog::query()->firstOrCreate(
                        [
                            'entity_type' => 'system_dependency',
                            'event_name' => 'DATABASE_SERVICE_UNAVAILABLE',
                            'entity_id' => $incident['id'],
                        ],
                        [
                            'actor_id' => $incident['actor_id'] ?? null,
                            'actor_role' => $incident['actor_role'] ?? null,
                            'branch_id' => $incident['branch_id'] ?? null,
                            'reason' => 'La operación fue rechazada porque la base de datos no respondió; no se guardó ni se dejó en cola para ejecutarse después.',
                            'ip_address' => $incident['ip_address'] ?? null,
                            'user_agent' => $incident['user_agent'] ?? null,
                            'request_id' => $incident['request_id'] ?? null,
                            'correlation_id' => $incident['correlation_id'] ?? null,
                            'trace_id' => $incident['trace_id'] ?? null,
                            'result' => 'FAILURE',
                            'new_value' => [
                                'affected_service' => 'DATABASE',
                                'impact' => 'La base de datos no respondía. La operación y sus correos asociados fueron cancelados.',
                                'method' => $incident['method'] ?? null,
                                'path' => $incident['path'] ?? null,
                                'sql_state' => $incident['sql_state'] ?? null,
                                'driver_code' => $incident['driver_code'] ?? null,
                                'technical_message' => $incident['technical_message'] ?? null,
                                'occurred_at' => $incident['occurred_at'] ?? null,
                            ],
                        ],
                    );
                    $imported++;
                } catch (Throwable) {
                    $pending[] = $line;
                }
            }

            ftruncate($handle, 0);
            rewind($handle);
            if ($pending !== []) {
                fwrite($handle, implode('', $pending));
            }
            fflush($handle);

            return $imported;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(): string
    {
        return (string) config('observability.database_incident_path', storage_path('logs/database-incidents.jsonl'));
    }

    private function safe(callable $resolver): mixed
    {
        try {
            return $resolver();
        } catch (Throwable) {
            return null;
        }
    }
}
