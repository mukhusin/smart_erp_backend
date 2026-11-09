<?php

namespace Modules\Core\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Modules\Core\Http\Resources\LoginBrandingResource;

/**
 * @tags Auth
 */
class LoginBrandingController extends Controller
{
    /**
     * Login page branding.
     *
     * Logo, background and announcements of an organization for the login page, plus announcements for everyone.
     *
     * @unauthenticated
     */
    #[QueryParameter('org', description: 'Organization slug from the login link, e.g. "kairuki".', type: 'string')]
    public function __invoke(Request $request): LoginBrandingResource
    {
        $slug = (string) $request->query('org', '');
        $company = $slug !== '' ? Company::where('slug', $slug)->where('is_active', true)->first() : null;

        return new LoginBrandingResource($company);
    }
}
