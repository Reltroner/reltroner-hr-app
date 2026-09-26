<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireLegacyLoginEnabled;
use App\Http\Middleware\RequireLegacyRegistrationEnabled;
use App\Http\Middleware\RequireLocalPasswordManagementEnabled;
use App\Http\Middleware\RequireProfileDeletionEnabled;
use App\Http\Middleware\ValidateOidcSessionBinding;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            ValidateOidcSessionBinding::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->appendToPriorityList(
            StartSession::class,
            ValidateOidcSessionBinding::class,
        );

        $middleware->alias([
            'role' => CheckRole::class,
            'legacy.login' => RequireLegacyLoginEnabled::class,
            'legacy.registration' => RequireLegacyRegistrationEnabled::class,
            'local.password' => RequireLocalPasswordManagementEnabled::class,
            'profile.deletion' => RequireProfileDeletionEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
