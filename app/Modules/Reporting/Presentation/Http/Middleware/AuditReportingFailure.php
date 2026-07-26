<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Middleware;

use App\Models\User;
use App\Modules\Reporting\Application\Services\ReportAuditRecorder;
use App\Modules\Reporting\Application\Services\ReportAuthorizationService;
use App\Modules\Reporting\Application\Services\ReportRegistry;
use App\Modules\Reporting\Application\Services\ReportScopeResolver;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuditReportingFailure
{
    public function __construct(
        private ReportAuditRecorder $audit,
        private ReportRegistry $registry,
        private ReportAuthorizationService $authorization,
        private ReportScopeResolver $scopes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (ReportingException $exception) {
            $this->recordFailure($request, $exception->errorCode());

            throw $exception;
        }

        $errorCode = $this->responseErrorCode($response);
        if ($errorCode !== null) {
            $this->recordFailure($request, $errorCode);
        }

        return $response;
    }

    private function correlation(Request $request): string
    {
        $value = (string) $request->header('X-Request-Id', '');

        return Str::isUuid($value) ? $value : (string) Str::uuid();
    }

    private function responseErrorCode(Response $response): ?string
    {
        $payload = json_decode((string) $response->getContent(), true);
        $errorCode = is_array($payload) ? ($payload['error']['code'] ?? null) : null;

        return is_string($errorCode) && str_starts_with($errorCode, 'REPORT_')
            ? $errorCode
            : null;
    }

    private function recordFailure(Request $request, string $errorCode): void
    {
        $correlation = $this->correlation($request);
        $actor = $request->user();
        $definition = null;
        $scope = null;
        try {
            $code = $request->route('code');
            if (is_string($code)) {
                $definition = $this->registry->get($code);
            }
            if ($actor instanceof User) {
                $scope = $this->scopes->resolve($actor, $this->authorization->role($actor));
            }
        } catch (Throwable) {
            // The rejected attempt is still recorded without unverified metadata.
        }
        $this->audit->denied(
            $actor instanceof User ? $actor : null,
            $definition,
            $scope,
            $request->except(['token', 'password']),
            $correlation,
            $errorCode,
        );
    }
}
