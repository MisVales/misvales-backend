<?php

namespace Tests\Feature;

use App\Http\Middleware\TraceRequest;
use App\Jobs\PersistOperationalHttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TraceRequestTest extends TestCase
{
    public function test_it_keeps_correlation_headers_without_scheduling_persistence_when_disabled(): void
    {
        config()->set('observability.operational_http_requests', false);
        Queue::fake();

        $response = app(TraceRequest::class)->handle(
            Request::create('/api/v1/trace-test', 'GET'),
            static fn (): Response => new Response(status: 204),
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertNotEmpty($response->headers->get('X-Correlation-Id'));
        $this->assertNotEmpty($response->headers->get('X-Trace-Id'));
        Queue::assertNothingPushed();
    }

    public function test_it_uses_immediate_operational_persistence_when_enabled(): void
    {
        config()->set('observability.operational_http_requests', true);
        config()->set('observability.queue_connection', 'sync');
        config()->set('observability.expose_server_timing', true);
        Queue::fake();

        $request = Request::create('/api/v1/trace-test', 'GET');
        $response = app(TraceRequest::class)->handle(
            $request,
            static function (Request $request): Response {
                $request->attributes->set('db_query_count', 4);
                $request->attributes->set('db_duration_ms', 12.45);
                $request->attributes->set('db_slow_query_count', 1);

                return new Response(status: 204);
            },
        );
        $this->assertStringContainsString('db;dur=12.45', (string) $response->headers->get('Server-Timing'));
        Queue::assertPushed(PersistOperationalHttpRequest::class, function (PersistOperationalHttpRequest $job) use ($response): bool {
            return $job->connection === 'sync'
                && $job->record['event'] === 'HTTP_REQUEST_COMPLETED'
                && $job->record['request_id'] === $response->headers->get('X-Request-Id')
                && $job->record['status_code'] === 204
                && $job->record['context']['db_query_count'] === 4
                && $job->record['context']['db_duration_ms'] === 12.45
                && $job->record['context']['db_slow_query_count'] === 1;
        });
    }

    public function test_the_queued_job_persists_the_operational_record(): void
    {
        $requestId = 'trace-job-test-'.str()->uuid();
        $record = [
            'channel' => 'OPERATION',
            'level' => 'INFO',
            'event' => 'HTTP_REQUEST_COMPLETED',
            'request_id' => $requestId,
            'method' => 'GET',
            'path' => '/api/v1/trace-test',
            'status_code' => 204,
            'duration_ms' => 1,
            'context' => [],
            'occurred_at' => now(),
        ];

        (new PersistOperationalHttpRequest($record))->handle();

        $this->assertDatabaseHas('operational_logs', [
            'request_id' => $requestId,
            'status_code' => 204,
        ]);
    }
}
