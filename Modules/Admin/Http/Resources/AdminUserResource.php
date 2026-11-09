<?php

namespace Modules\Admin\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as the company admin sees it. Personal details are read-only here: they come from the HR employee record.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->employee;

        return [
            'id' => $this->id,
            'name' => $this->display_name,
            'username' => $this->username,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
            /** False until the user has set a password from their invite. */
            'has_set_password' => $this->email_verified_at !== null,
            /** @var array{id: int, name: string}|null */
            'branch' => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null,
            /** @var array{id: int, employee_no: string, department: string|null, position: string|null}|null */
            'employee' => $employee ? [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'department' => $employee->department()->withoutGlobalScopes()->value('name'),
                'position' => $employee->jobPosition()->withoutGlobalScopes()->value('title'),
            ] : null,
            /** @var list<string> */
            'roles' => $this->roles->pluck('name')->sort()->values()->all(),
            /** @var list<string> Permissions given to the user directly (not through a role). */
            'direct_permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->sort()->values()->all(), []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
