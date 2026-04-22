<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;

class CheckModuleAccess
{
    public function __construct(protected ModuleAccessService $service) {}

    public function handle(Request $request, Closure $next, string $slug)
    {
        if (!$this->service->hasAccess($slug)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Module not available for your account.'], 403);
            }
            return redirect()->route('dashboard')
                ->with('error', 'You do not have access to that module.');
        }
        return $next($request);
    }
}
