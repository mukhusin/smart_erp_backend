<?php

namespace Modules\Hr\Http\Resources;

use App\Models\JobPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobPosition
 */
class JobPositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $department = $this->department;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'is_active' => $this->is_active,
            /** @var array{id: int, name: string, branch: string|null}|null */
            'department' => $department ? ['id' => $department->id, 'name' => $department->name, 'branch' => $department->branch?->name] : null,
            /** Current employees (not terminated) in this position. */
            'staff_count' => (int) ($this->staff_count ?? 0),
        ];
    }
}
