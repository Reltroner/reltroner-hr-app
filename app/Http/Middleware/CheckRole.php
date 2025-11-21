<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\Employee;

/**
 * CheckRole middleware
 *
 * Usage:
 *  - Route::middleware(['auth', 'role:Admin,HR Manager'])
 *  - Route::middleware(['auth', 'role:Admin']) // single role
 *
 * Behavior:
 *  - If no roles passed to the middleware, it will allow the request (no restriction).
 *    (Change behavior to abort by default if you want stricter policy.)
 *  - It tries to identify user's role from multiple places in order:
 *      1) session('role')
 *      2) $user->employee->role->title
 *      3) $user->role (column)
 *      4) Spatie 'hasRole' if available
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

        // 3) Try to read role from session first (fallback), then from user->employee->role->title,
        //    then from user->role column, and if using Spatie, we will use hasRole() check later.
        $roleFromSession = session('role');
        $roleFromEmployee = null;
        $roleFromUserColumn = $user->role ?? null;

        // Try employee relation safely
        try {
            if (method_exists($user, 'employee') || property_exists($user, 'employee')) {
                $employeeRelation = $user->employee; // may be null
                if ($employeeRelation) {
                    // If employee->role is relation object or attribute
                    $roleFromEmployee = optional(optional($employeeRelation)->role)->title
                                        ?? optional($employeeRelation)->role;
                    // Save session keys for convenience (only if value valid)
                    if (! empty($roleFromEmployee)) {
                        $request->session()->put('role', $roleFromEmployee);
                        $request->session()->put('employee_id', $employeeRelation->id ?? null);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Don't break the request for noisy relation errors — log for debugging
            Log::warning('CheckRole: failed to fetch employee role', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        // 4) Determine current role string (prioritize session, then employee, then user column)
        $currentRole = $roleFromSession ?? $roleFromEmployee ?? $roleFromUserColumn;

        // 5) If no allowed rules provided, allow the request (open). Change this if you want default-deny.
        if (empty($allowed)) {
            // no restriction applied by middleware
            return $next($request);
        }

        // 6) If using Spatie Roles & Permissions package, prefer hasRole check if available.
        if (method_exists($user, 'hasRole')) {
            // normalize allowed roles and check
            foreach ($allowed as $roleName) {
                if ($user->hasRole($roleName)) {
                    return $next($request);
                }
            }
        }

        // 7) Fallback: match current role string against allowed list (case-insensitive)
        $currentNormalized = $currentRole ? Str::lower(trim((string) $currentRole)) : null;
        $allowedNormalized = array_map(function ($v) {
            return Str::lower(trim($v));
        }, $allowed);

        if ($currentNormalized !== null && in_array($currentNormalized, $allowedNormalized, true)) {
            return $next($request);
        }

        // 8) Not authorized: log details and return proper response (JSON or abort 403)
        Log::warning('Unauthorized access attempt (CheckRole)', [
            'user_id' => $user->id ?? null,
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
        $allowed = array_values(array_unique(array_filter($allowed, function ($v) {
            return $v !== null && $v !== '';
        })));

        return $allowed;
    }
}
