<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Auth\AuthTransitionPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLegacyLoginEnabled
{
    public function __construct(
        protected AuthTransitionPolicy $policy
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->policy->legacyLoginEnabled()) {
            abort(404);
        }

        return $next($request);
    }
}
