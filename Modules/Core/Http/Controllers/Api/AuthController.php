<?php

namespace Modules\Core\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Actions\Auth\Login;
use Modules\Core\Actions\Auth\Logout;
use Modules\Core\Http\Requests\LoginRequest;
use Modules\Core\Http\Resources\MeResource;

/**
 * @tags Auth
 */
class AuthController extends Controller
{
    /**
     * Log in (SPA cookie session).
     *
     * Call `GET /sanctum/csrf-cookie` first, then send the `X-XSRF-TOKEN` header.
     * Returns the same data as `GET /me`.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request, Login $login): MeResource
    {
        $user = $login->handle($request, $request->validated('username'), $request->validated('password'));

        return new MeResource($user);
    }

    /**
     * Log out.
     */
    public function logout(Request $request, Logout $logout): Response
    {
        $logout->handle($request);

        return response()->noContent();
    }
}
