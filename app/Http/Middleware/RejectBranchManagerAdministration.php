<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectBranchManagerAdministration
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->hasRole('branch_manager') && ! $user->hasRole('general_manager')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
