<?php

namespace Modules\Platform\Http\Resources;

use App\Models\ErpModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin ErpModule
 */
class ModuleCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            /** Bootstrap Icons name without the "bi-" prefix. */
            'icon' => $this->icon ? Str::after($this->icon, 'bi-') : null,
            'category' => $this->category,
            /** Core modules are always available to every company. */
            'is_core' => (bool) $this->is_core,
        ];
    }
}
