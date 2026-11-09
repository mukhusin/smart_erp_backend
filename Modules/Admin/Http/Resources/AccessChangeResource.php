<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\AccessChange;

/**
 * @mixin AccessChange
 */
class AccessChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** roles | permissions | deactivate | activate */
            'type' => $this->type,
            /** @var array<string, mixed>|list<string> */
            'before' => $this->before,
            /** @var array<string, mixed>|list<string> */
            'after' => $this->after,
            'reason' => $this->reason,
            /** pending | applied | rejected */
            'status' => $this->status,
            'requested_by' => $this->requestedBy?->display_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
