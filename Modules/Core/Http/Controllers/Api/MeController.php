<?php

namespace Modules\Core\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Resources\MeResource;

/**
 * @tags Auth
 */
class MeController extends Controller
{
    /**
     * Current user.
     *
     * User, linked employee, company, modules enabled for the company, roles and permissions.
     * The frontend builds its menu and hides actions from this; the API still checks every request.
     */
    public function __invoke(Request $request): MeResource
    {
        return new MeResource($request->user());
    }
}
