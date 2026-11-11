<?php

namespace Modules\Hr\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Modules\Hr\Support\EmployeeScope;
use Symfony\Component\HttpFoundation\Response;

/** HR routes: the user needs an employee-view permission, and an `{employee}` must be within their scope. */
class EnsureEmployeeScope
{
    public function __construct(private readonly EmployeeScope $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $this->scope->canBrowse($user), 403, "You don't have access to employees.");

        $employee = $request->route('employee');
        if ($employee instanceof Employee && ! $this->scope->allows($user, $employee)) {
            abort(404);
        }

        return $next($request);
    }
}
