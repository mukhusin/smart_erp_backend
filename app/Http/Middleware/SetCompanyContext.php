<?php

namespace App\Http\Middleware;

use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;

class SetCompanyContext
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $this->context->resolve();
        return $next($request);
    }
}
