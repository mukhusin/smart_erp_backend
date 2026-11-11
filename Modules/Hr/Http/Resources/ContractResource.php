<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmployeeContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * @mixin EmployeeContract
 */
class ContractResource extends JsonResource
{
    private const ACTIONS = [
        'created' => 'Contract given',
        'adjusted' => 'Contract adjusted',
        'activated' => 'Contract started',
        'ended' => 'Contract ended',
        'auto_ended' => 'Contract expired',
        'cancelled' => 'Contract cancelled',
        'restored' => 'Contract restored',
    ];

    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::today();
        $open = in_array($this->status, ['active', 'waiting'], true);
        $total = max(1, $this->start_date->diffInDays($this->end_date));
        $nature = $this->natureOfContract;

        return [
            'id' => $this->id,
            'contract_no' => $this->contract_no,
            /** @var array{id: int, name: string, code: string|null, employment_type: string|null}|null */
            'type' => $nature ? ['id' => $nature->id, 'name' => $nature->contract_name, 'code' => $nature->code, 'employment_type' => $nature->employment_type] : null,
            'basic_salary' => (float) $this->basic_salary,
            /** Months. */
            'period' => (int) $this->period,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'date_of_change' => $this->date_of_change?->toDateString(),
            /** `waiting`, `active`, or how it ended (see options `contract_end_reasons`). */
            'status' => $this->status,
            'status_label' => O::contractStatusLabel($this->status),
            'is_active' => $this->is_active,
            /** Days until the end date, for active and waiting contracts. */
            'days_left' => $open ? (int) $today->diffInDays($this->end_date, false) : null,
            /** Share of the contract period already passed (0-100), for the active contract. */
            'progress' => $this->is_active ? (int) min(100, max(0, round($this->start_date->diffInDays($today) / $total * 100))) : null,
            'comment' => $this->comment,
            'updated_by' => $this->updatedBy?->display_name,
            'updated_at' => $this->updated_at?->toIso8601String(),
            /** @var list<array{id: int, action: string, action_label: string, status_label: string|null, comment: string|null, by: string|null, at: string|null}> */
            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id' => $e->id,
                'action' => $e->action,
                'action_label' => self::ACTIONS[$e->action] ?? $e->action,
                'status_label' => O::contractStatusLabel($e->status),
                'comment' => $e->comment,
                'by' => $e->user?->display_name,
                'at' => $e->created_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
