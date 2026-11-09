<?php

namespace Modules\Core\Http\Resources;

use App\Models\ErpModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin ErpModule
 */
class ModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /** Key used by the frontend module manifest, e.g. "leave". */
            'slug' => $this->slug,
            'name' => $this->name,
            /** Bootstrap Icons name without the "bi-" prefix, e.g. "person-circle". */
            'icon' => $this->icon ? Str::after($this->icon, 'bi-') : null,
            'category' => $this->category,
        ];
    }
}
