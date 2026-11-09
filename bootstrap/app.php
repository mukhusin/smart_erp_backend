<?php

use App\Http\Middleware\EnsureSessionTimeout;
use App\Http\Middleware\SetCompanyContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA cookie auth: requests from the Angular app (stateful domains) get session + CSRF.
        $middleware->statefulApi();

        $middleware->web(append: [
            SetCompanyContext::class,
            EnsureSessionTimeout::class,
        ]);
        $middleware->api(append: [
            SetCompanyContext::class,
            EnsureSessionTimeout::class,
        ]);

        // No login page in the backend: unauthenticated requests get 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role'             => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'       => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'module'           => \App\Http\Middleware\CheckModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API only: every error (401, 403, 404, 422, 500) is returned as JSON, never a redirect or HTML page.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
