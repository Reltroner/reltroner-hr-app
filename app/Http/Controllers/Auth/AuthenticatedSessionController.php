<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Modules\Identity\Auth\AuthTransitionPolicy;
use App\Modules\Identity\Oidc\OidcLogoutService;
use App\Modules\Identity\Oidc\OidcSessionBinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(AuthTransitionPolicy $policy): View
    {
        return view('auth.login', [
            'legacyLoginEnabled' => $policy->legacyLoginEnabled(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, OidcLogoutService $oidcLogoutService): RedirectResponse
    {
        $wasOidcBound = OidcSessionBinding::has($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if (! $wasOidcBound) {
            return redirect('/');
        }

        $logoutUrl = $oidcLogoutService->buildLogoutUrl();

        return redirect()->away($logoutUrl);
    }
}
