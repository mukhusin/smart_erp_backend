<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Actions\SaveRole;
use Modules\Admin\Http\Controllers\Api\Concerns\InCompany;
use Modules\Admin\Http\Requests\SaveRoleRequest;
use Modules\Admin\Http\Resources\RoleResource;
use Spatie\Permission\Models\Role;

/**
 * The company's roles. Each company has its own roles and may create more.
 *
 * @tags Admin: Roles
 */
class RoleController extends Controller
{
    use InCompany;

    /**
     * List roles.
     */
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::where('company_id', app(CompanyContext::class)->id())->withCount('users')->with('permissions')->orderByDesc('is_system')->orderBy('name')->get()
        );
    }

    /**
     * Create a role.
     */
    public function store(SaveRoleRequest $request, SaveRole $save)
    {
        $role = $save->handle(null, $request->validated());

        return $this->show($role)->response()->setStatusCode(201);
    }

    /**
     * Show a role.
     */
    public function show(Role $role): RoleResource
    {
        $this->ensureInCompany($role);

        return new RoleResource($role->loadCount('users')->load('permissions'));
    }

    /**
     * Update a role.
     *
     * Name, description and the full permission list. The company-admin role cannot be changed.
     */
    public function update(SaveRoleRequest $request, Role $role, SaveRole $save): RoleResource
    {
        $this->ensureInCompany($role);

        return $this->show($save->handle($role, $request->validated()));
    }

    /**
     * Delete a role.
     *
     * Only roles you created and that no user has.
     */
    public function destroy(Role $role): Response
    {
        $this->ensureInCompany($role);
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'Built-in roles cannot be deleted.']);
        }
        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'Remove this role from all users before deleting it.']);
        }
        $role->delete();

        return response()->noContent();
    }
}
