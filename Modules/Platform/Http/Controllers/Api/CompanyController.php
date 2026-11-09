<?php

namespace Modules\Platform\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Modules\Platform\Actions\RegisterCompany;
use Modules\Platform\Actions\SendCompanyInvite;
use Modules\Platform\Actions\UpdateCompanyBranding;
use Modules\Platform\Http\Requests\UpdateCompanyBrandingRequest;
use Modules\Platform\Http\Requests\RegisterCompanyRequest;
use Modules\Platform\Http\Requests\UpdateCompanyModulesRequest;
use Modules\Platform\Http\Requests\UpdateCompanyRequest;
use Modules\Platform\Http\Resources\CompanyResource;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use App\Services\ModuleAccessService;
use Dedoc\Scramble\Attributes\QueryParameter;

/**
 * Client companies (tenants). System administrator only.
 *
 * @tags Platform: Companies
 */
class CompanyController extends Controller
{
    /**
     * List companies.
     *
     * Filters: `filter[search]` (name, short name, TIN), `filter[is_active]`. Sort: `name`, `created_at` (prefix `-` for descending).
     */
    #[QueryParameter('filter[search]', description: 'Part of the name, short name or TIN.', type: 'string')]
    #[QueryParameter('filter[is_active]', description: '1 = active, 0 = suspended.', type: 'boolean')]
    #[QueryParameter('sort', description: '`name`, `created_at`; prefix `-` for descending.', type: 'string', default: 'name')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 25)]
    public function index(): AnonymousResourceCollection
    {
        $companies = QueryBuilder::for(Company::class)
            ->withCount(['employees', 'users'])
            ->allowedFilters(
                AllowedFilter::callback('search', fn ($q, $v) => $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$v}%")->orWhere('short_name', 'like', "%{$v}%")->orWhere('tin', 'like', "%{$v}%"))),
                AllowedFilter::exact('is_active'),
            )
            ->allowedSorts('name', 'created_at')
            ->defaultSort('name')
            ->paginate(min((int) request('per_page', 25), 100))
            ->withQueryString();

        return CompanyResource::collection($companies);
    }

    /**
     * Register a company.
     *
     * Creates the company, its branches, enabled modules, default roles, and its first HR admin
     * (employee + user with role company-admin), then emails the admin a link to set their password.
     */
    public function store(RegisterCompanyRequest $request, RegisterCompany $register, SendCompanyInvite $invite)
    {
        $company = $register->handle($request->validated());
        $sent = $invite->handle($company);

        return $this->detail($company, $invite)
            ->additional(['meta' => ['invite_sent' => $sent]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a company.
     */
    public function show(Company $company, SendCompanyInvite $invite): CompanyResource
    {
        return $this->detail($company, $invite);
    }

    /**
     * Update a company.
     *
     * Send `is_active: false` to suspend it: its users can no longer log in or use the API.
     */
    public function update(UpdateCompanyRequest $request, Company $company, SendCompanyInvite $invite): CompanyResource
    {
        $company->update($request->validated());

        return $this->detail($company, $invite);
    }

    /**
     * Set the company's modules.
     *
     * Replaces the list of enabled modules (with optional expiry dates).
     */
    public function updateModules(UpdateCompanyModulesRequest $request, Company $company, ModuleAccessService $modules, SendCompanyInvite $invite): CompanyResource
    {
        $wanted = collect($request->validated('modules'))->keyBy('slug');

        foreach ($company->modules()->where('is_enabled', true)->pluck('module_slug') as $slug) {
            if (! $wanted->has($slug)) {
                $modules->disableModule($company->id, $slug);
            }
        }
        foreach ($wanted as $slug => $module) {
            $modules->enableModule($company->id, $slug, isset($module['expires_at']) ? new \DateTimeImmutable($module['expires_at']) : null);
        }
        Cache::forget("company_modules_{$company->id}");

        return $this->detail($company, $invite);
    }

    /**
     * Update branding.
     *
     * Logo, login page photo and theme colors (multipart/form-data). Send `remove_logo` / `remove_login_background`
     * to go back to the default, or an empty color for the default color.
     */
    public function updateBranding(UpdateCompanyBrandingRequest $request, Company $company, UpdateCompanyBranding $branding, SendCompanyInvite $invite): CompanyResource
    {
        $branding->handle($company, $request->validated());

        return $this->detail($company, $invite);
    }

    /**
     * Resend the admin invite.
     *
     * Emails the company admin a new link to set their password.
     */
    public function resendInvite(Company $company, SendCompanyInvite $invite)
    {
        $sent = $invite->handle($company);

        return response()->json(['meta' => ['invite_sent' => $sent]], $sent ? 200 : 422);
    }

    private function detail(Company $company, SendCompanyInvite $invite): CompanyResource
    {
        $company->load(['branches', 'modules'])->loadCount(['employees', 'users']);

        return (new CompanyResource($company))->withAdmin($invite->companyAdmin($company));
    }
}
