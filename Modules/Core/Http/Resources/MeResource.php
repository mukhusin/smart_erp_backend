<?php

namespace Modules\Core\Http\Resources;

use App\Models\ErpModule;
use App\Models\User;
use Modules\Core\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $access = app(AccessService::class);
        $employee = $this->employee;
        $company = $this->company;
        $branch = $this->branch;

        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->display_name,
                'username' => $this->username,
                'email' => $this->email,
                'avatar_url' => $this->avatar_url,
                /** System owner: manages client companies only, has no company. */
                'is_platform_admin' => $this->isPlatformAdmin(),
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
                'slug' => $company->slug,
                'name' => $company->name,
                'logo_url' => $company->logo_url,
                /** Theme colors (#rrggbb) or null for the ERP default. */
                'primary_color' => $company->primary_color,
                'secondary_color' => $company->secondary_color,
            ] : null,
            /** The user's home branch. */
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_headquarters' => (bool) $branch->is_headquarters,
            ] : null,
            /** Modules this user can open: enabled for the company, not locked for their branch or for them. */
            'modules' => ModuleResource::collection(
                ErpModule::whereIn('slug', $access->modules($this->resource))->orderBy('sort_order')->get()
            ),
            /**
             * Dashboard tiles: every module available to the user's company and branch. state: open | locked (locked for this user) | no_permission.
             *
             * `parent`: the module this one is opened from (e.g. leave inside hr); such modules have no dashboard tile.
             *
             * @var list<array{slug: string, name: string, description: string|null, icon: string|null, color: string|null, category: string|null, parent: string|null, state: string}>
             */
            'module_tiles' => $access->moduleTiles($this->resource),
            /** @var list<string> Assigned roles plus roles handed over to the user right now. */
            'roles' => $access->roles($this->resource),
            /** @var list<string> Effective permission names, e.g. "leave.approve-hr". The UI shows actions from this list only. */
            'permissions' => $access->permissions($this->resource),
            /**
             * Roles the user holds temporarily for someone else (handover).
             *
             * @var list<array{role: string, for_user: string|null, ends_at: string|null}>
             */
            'acting_roles' => $access->activeHandovers($this->resource)->map(fn ($h) => [
                'role' => $h->role->name,
                'for_user' => $h->fromUser?->display_name,
                'ends_at' => $h->ends_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
