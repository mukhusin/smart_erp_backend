<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\DefaultRoles;
use Spatie\Permission\Models\Role;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            /** Created with the company. */
            'is_system' => (bool) $this->is_system,
            /** company-admin: always every permission, cannot be edited or deleted. */
            'is_locked' => $this->name === DefaultRoles::COMPANY_ADMIN,
            'users_count' => $this->whenCounted('users'),
            /** @var list<string> */
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->sort()->values()->all()),
        ];
    }
}
