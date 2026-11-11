<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmployeeNumberFormat;
use App\Models\EmployeeNumberFormatSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hr\Support\EmployeeNumbers;

/**
 * @mixin EmployeeNumberFormat
 */
class NumberFormatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'separator' => (string) $this->separator,
            'is_default' => $this->is_default,
            /** The number the next employee would get. */
            'next_number' => app(EmployeeNumbers::class)->nextIn($this->resource),
            /** @var list<array{type: string, type_label: string, value: string|null, length: int|null, start_at: int|null}> */
            'segments' => $this->segments->map(fn ($s) => [
                'type' => $s->type,
                'type_label' => EmployeeNumberFormatSegment::TYPES[$s->type] ?? $s->type,
                'value' => $s->value,
                'length' => $s->length,
                'start_at' => $s->start_at,
            ])->values()->all(),
            /** @var list<array{id: int, name: string}> */
            'contract_types' => $this->whenLoaded('contractTypes', fn () => $this->contractTypes->map(fn ($t) => ['id' => $t->id, 'name' => $t->contract_name])->values()->all()),
        ];
    }
}
