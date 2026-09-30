<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CheckRole middleware
 *
 * Usage:
 *  - Route::middleware(['auth', 'role:Admin,HR Manager'])
 *  - Route::middleware(['auth', 'role:Admin'])
 *
 * Authority:
 *  - Authority is derived strictly from Authenticated User -> Employee -> Role -> Role.title.
 *  - Presentation session keys ('role', 'employee_id') are synchronized as derived output-only state.
 *  - Missing employee, missing role, blank role title, or empty allowed roles fail closed with 403.
 */
class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string[]  ...$roles  Roles passed via middleware parameters (can be comma-separated too)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        // 1) Ensure authenticated
        $user = $request->user();
        if (! $user) {
            // If AJAX/API call -> JSON, otherwise redirect to login
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('login');
        }

        // 2) Normalize middleware roles param
        // Accepts: role:Admin,HR Manager  OR role:"Admin" (multiple args)
        $allowed = $this->normalizeAllowedRoles($roles);

        // Fail closed if no allowed roles are declared on the middleware invocation
        if (empty($allowed)) {
            return $this->deny($request, 'CheckRole: no allowed roles configured', $user->id ?? null, null, []);
        }

        // 3) Resolve authoritative role from User -> Employee -> Role.title
        $employeeRelation = null;
        $roleTitle = null;

        try {
            if (method_exists($user, 'employee') || property_exists($user, 'employee')) {
                $employeeRelation = $user->employee;
            }
        } catch (\Throwable $e) {
            Log::warning('CheckRole: failed to resolve employee relation', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return $this->deny($request, 'CheckRole: exception resolving employee relation', $user->id ?? null);
        }

        if (! $employeeRelation) {
            return $this->deny($request, 'CheckRole: user has no employee relation', $user->id ?? null);
        }

        try {
            $roleRelation = $employeeRelation->role;
            if ($roleRelation && ! empty($roleRelation->title) && trim((string) $roleRelation->title) !== '') {
                $roleTitle = trim((string) $roleRelation->title);
            }
        } catch (\Throwable $e) {
            Log::warning('CheckRole: failed to resolve employee role', [
                'user_id' => $user->id ?? null,
                'employee_id' => $employeeRelation->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return $this->deny($request, 'CheckRole: exception resolving employee role', $user->id ?? null);
        }

        if ($roleTitle === null) {
            return $this->deny($request, 'CheckRole: employee has missing or invalid role', $user->id ?? null);
        }

        // 4) Synchronize presentation session cache (output-only, never authorization input)
        // Must occur for the current request before evaluating whether role is in allowed list
        if ($request->hasSession()) {
            $request->session()->put('role', $roleTitle);
            $request->session()->put('employee_id', $employeeRelation->id);
        }

        // 5) Evaluate whether authoritative role is in allowed list (case-insensitive)
        $currentNormalized = Str::lower($roleTitle);
        $allowedNormalized = array_map(function ($v) {
            return Str::lower(trim($v));
        }, $allowed);

        if (in_array($currentNormalized, $allowedNormalized, true)) {
            return $next($request);
        }

        return $this->deny($request, 'CheckRole: user role not authorized', $user->id ?? null, $roleTitle, $allowed);
    }

    /**
     * Terminate unauthorized access with logging and proper response.
     */
    private function deny(Request $request, string $reason, ?int $userId = null, ?string $currentRole = null, array $allowed = [])
    {
        Log::warning('Unauthorized access attempt (CheckRole)', [
            'reason' => $reason,
            'user_id' => $userId,
            'current_role' => $currentRole,
            'allowed_roles' => $allowed,
            'route' => $request->route()?->getName(),
            'uri' => $request->getRequestUri(),
            'method' => $request->method(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Forbidden. You do not have permission to access this resource.'], 403);
        }

        abort(403, 'You do not have permission to access this resource.');
    }

    /**
     * Normalize roles provided via middleware parameters into flat array.
     *
     * Examples:
     *  - ['Admin'] -> ['Admin']
     *  - ['Admin,HR Manager'] -> ['Admin','HR Manager']
     *  - ['Admin','HR Manager'] -> ['Admin','HR Manager']
     *
     * @param  array  $roles
     * @return array
     */
    protected function normalizeAllowedRoles(array $roles): array
    {
        $allowed = [];

        foreach ($roles as $r) {
            if ($r === null || $r === '') {
                continue;
            }
            // split by comma so both 'Admin,HR' and 'Admin','HR' work
            $parts = array_filter(array_map('trim', explode(',', (string) $r)));
            $allowed = array_merge($allowed, $parts);
        }

        // remove duplicates and empty strings
        return array_values(array_unique(array_filter($allowed, function ($v) {
            return $v !== null && $v !== '';
        })));
    }
}
