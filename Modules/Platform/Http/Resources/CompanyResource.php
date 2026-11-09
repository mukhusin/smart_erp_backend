<?php

namespace Modules\Platform\Http\Resources;

use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\CompanyModule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    private bool $includeAdmin = false;
    private ?User $adminUser = null;

    /** Detail view: include the company admin (first HR user). */
    public function withAdmin(?User $admin): static
    {
        $this->includeAdmin = true;
        $this->adminUser = $admin;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'registration_number' => $this->registration_number,
            'tin' => $this->tin,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'currency' => $this->currency,
            'fiscal_year_start' => $this->fiscal_year_start,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'employees_count' => $this->whenCounted('employees'),
            'users_count' => $this->whenCounted('users'),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn (CompanyBranch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'city' => $b->city,
                'is_headquarters' => (bool) $b->is_headquarters,
                'is_active' => (bool) $b->is_active,
            ])),
            'modules' => $this->whenLoaded('modules', fn () => $this->modules->where('is_enabled', true)->values()->map(fn (CompanyModule $m) => [
                'slug' => $m->module_slug,
                'expires_at' => $m->expires_at?->toDateString(),
            ])),
            /** The company admin (first HR user). Only on the detail endpoint. */
            'admin' => $this->when($this->includeAdmin, fn () => $this->adminUser ? [
                'id' => $this->adminUser->id,
                'name' => $this->adminUser->display_name,
                'username' => $this->adminUser->username,
                'email' => $this->adminUser->email,
                /** False until the admin has used the invite link. */
                'has_set_password' => $this->adminUser->email_verified_at !== null,
            ] : null),
        ];
    }
}
