<?php

namespace Modules\Core\Http\Resources;

use App\Models\ErpModule;
use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->employee;
        $company = $this->company;

        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->display_name,
                'username' => $this->username,
                'email' => $this->email,
                'avatar_url' => $this->avatar_url,
            ],
            'employee' => $employee ? [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'full_name' => $this->display_name,
                'department_id' => $employee->department_id,
                'job_position_id' => $employee->job_position_id,
            ] : null,
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
            ] : null,
            /** Modules the user's company can use (core modules are always included). */
            'modules' => ModuleResource::collection(
                app(ModuleAccessService::class)->allForDashboard()->filter(fn (ErpModule $m) => $m->has_access)->values()
            ),
            /** @var list<string> */
            'roles' => $this->getRoleNames()->values()->all(),
            /** @var list<string> Permission names, e.g. "leave.approve". */
            'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
