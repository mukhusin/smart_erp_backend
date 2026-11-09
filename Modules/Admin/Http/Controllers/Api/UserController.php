<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Admin\Actions\ChangeUserAccess;
use Modules\Admin\Http\Controllers\Api\Concerns\InCompany;
use Modules\Admin\Http\Requests\SetUserPermissionsRequest;
use Modules\Admin\Http\Requests\SetUserRolesRequest;
use Modules\Admin\Http\Requests\UserStatusRequest;
use Modules\Admin\Http\Resources\AccessChangeResource;
use Modules\Admin\Http\Resources\AdminUserResource;
use Modules\Admin\Models\AccessChange;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Company users and their access. Users are created from HR (employees); here the company admin only manages
 * access: roles, direct permissions, activation. Personal details cannot be changed or deleted here.
 *
 * @tags Admin: Users
 */
class UserController extends Controller
{
    use InCompany;

    /**
     * List users.
     */
    #[QueryParameter('filter[search]', description: 'Name, username, email or employee number.', type: 'string')]
    #[QueryParameter('filter[role]', description: 'Role name.', type: 'string')]
    #[QueryParameter('filter[is_active]', type: 'boolean')]
    #[QueryParameter('filter[branch_id]', type: 'integer')]
    #[QueryParameter('sort', description: '`username`, `created_at`; prefix `-` for descending.', type: 'string', default: 'username')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 25)]
    public function index(): AnonymousResourceCollection
    {
        $users = QueryBuilder::for(User::where('company_id', app(CompanyContext::class)->id())->with(['roles', 'branch', 'employee']))
            ->allowedFilters(
                AllowedFilter::callback('search', fn ($q, $v) => $q->where(fn ($q) => $q
                    ->where('username', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")
                    ->orWhereHas('employee', fn ($e) => $e->withoutGlobalScopes()->where(fn ($e) => $e
                        ->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->orWhere('employee_no', 'like', "%{$v}%"))))),
                AllowedFilter::callback('role', fn ($q, $v) => $q->whereHas('roles', fn ($r) => $r->where('name', $v))),
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('branch_id', 'company_branch_id'),
            )
            ->allowedSorts('username', 'created_at')
            ->defaultSort('username')
            ->paginate(min((int) request('per_page', 25), 100))
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    /**
     * Show a user.
     */
    public function show(User $user): AdminUserResource
    {
        $this->ensureInCompany($user);

        return new AdminUserResource($user->load(['roles', 'permissions', 'branch', 'employee']));
    }

    /**
     * Set the user's roles.
     *
     * Replaces the user's roles (a user can have several). Recorded in the access history.
     */
    public function updateRoles(SetUserRolesRequest $request, User $user, ChangeUserAccess $access): AdminUserResource
    {
        $this->ensureInCompany($user);
        $access->setRoles($user, $request->validated('roles'), $request->validated('reason'));

        return $this->show($user->refresh());
    }

    /**
     * Set the user's direct permissions.
     *
     * Permissions on top of the user's roles. Prefer roles; use this for exceptions.
     */
    public function updatePermissions(SetUserPermissionsRequest $request, User $user, ChangeUserAccess $access): AdminUserResource
    {
        $this->ensureInCompany($user);
        $access->setDirectPermissions($user, $request->validated('permissions'), $request->validated('reason'));

        return $this->show($user->refresh());
    }

    /**
     * Deactivate a user.
     *
     * The user is logged out and cannot log in. Nothing is deleted. A reason is required.
     */
    public function deactivate(UserStatusRequest $request, User $user, ChangeUserAccess $access): AdminUserResource
    {
        $this->ensureInCompany($user);
        $access->deactivate($user, $request->validated('reason'));

        return $this->show($user->refresh());
    }

    /**
     * Reactivate a user.
     */
    public function activate(UserStatusRequest $request, User $user, ChangeUserAccess $access): AdminUserResource
    {
        $this->ensureInCompany($user);
        $access->activate($user, $request->validated('reason'));

        return $this->show($user->refresh());
    }

    /**
     * Access history of a user.
     *
     * Every role, permission and activation change, newest first.
     */
    public function history(User $user): AnonymousResourceCollection
    {
        $this->ensureInCompany($user);

        return AccessChangeResource::collection(
            AccessChange::where('user_id', $user->id)->with('requestedBy')->latest('id')->paginate(25)
        );
    }
}
