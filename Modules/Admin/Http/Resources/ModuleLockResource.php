<?php

namespace Modules\Admin\Http\Resources;

use App\Models\ErpModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Models\ModuleLock;

/**
 * @mixin ModuleLock
 */
class ModuleLockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_slug' => $this->module_slug,
            'module_name' => ErpModule::where('slug', $this->module_slug)->value('name') ?? $this->module_slug,
            /** @var array{id: int, name: string}|null */
            'branch' => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null,
            /** @var array{id: int, name: string}|null */
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->display_name] : null,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
