<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Providers;

use App\Modules\Reporting\Domain\Contracts\ReportOutboxPublisher;
use App\Modules\Reporting\Domain\Contracts\ReportReadModelGateway;
use App\Modules\Reporting\Domain\Contracts\ReportResultStoreInterface;
use App\Modules\Reporting\Infrastructure\Integrations\UnavailableReportOutboxPublisher;
use App\Modules\Reporting\Infrastructure\Integrations\UnavailableReportReadModelGateway;
use App\Modules\Reporting\Infrastructure\Persistence\DatabaseReportResultStore;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;
use App\Modules\Reporting\Presentation\Http\Policies\ReportRunPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReportReadModelGateway::class, UnavailableReportReadModelGateway::class);
        $this->app->bind(ReportOutboxPublisher::class, UnavailableReportOutboxPublisher::class);
        $this->app->bind(ReportResultStoreInterface::class, DatabaseReportResultStore::class);
    }

    public function boot(): void
    {
        Gate::policy(ReportRun::class, ReportRunPolicy::class);
        RateLimiter::for('reporting-queries', static fn (Request $request): Limit => self::limit(
            $request,
            (int) config('reporting.rate_limit_per_minute', 30),
        ));
        RateLimiter::for('reporting-runs', static fn (Request $request): Limit => self::limit(
            $request,
            (int) config('reporting.run_rate_limit_per_minute', 5),
        ));
    }

    private static function key(Request $request): string
    {
        return $request->user() === null
            ? 'ip:'.hash('sha256', (string) $request->ip())
            : 'user:'.$request->user()->getAuthIdentifier();
    }

    private static function limit(Request $request, int $attempts): Limit
    {
        return Limit::perMinute($attempts)
            ->by(self::key($request))
            ->response(static fn (Request $request, array $headers) => response()->json([
                'error' => [
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'message' => 'Se excedió el límite técnico de consultas de reportes.',
                    'fields' => (object) [],
                    'details' => (object) [],
                    'request_id' => $request->header('X-Request-Id'),
                ],
            ], 429, $headers));
    }
}
