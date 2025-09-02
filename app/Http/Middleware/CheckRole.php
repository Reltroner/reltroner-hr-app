<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Employee;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $roleTitle = session('role') // fallback kalau app kamu kadang set role di session
        ?? optional(optional(optional($user)->employee)->role)->title
        ?? ($user->role ?? null); // kalau suatu saat ada kolom role di users
        $employeeID = auth()->user()->employee_id;
        $employee = Employee::find($employeeID);
        $request->session()->put('role', $employee->role->title);
        $request->session()->put('employee_id', $employee->id);
        if (!in_array($employee->role->title, $roles)) {
            abort(403, 'Unauthorized action.');
        }
        if (!$user || !$roleTitle || !in_array($roleTitle, $allowed, true)) {
            abort(403);
        }
        return $next($request);
    }
}
