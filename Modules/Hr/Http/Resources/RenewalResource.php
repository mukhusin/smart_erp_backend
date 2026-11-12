<?php

namespace Modules\Hr\Http\Resources;

use App\Models\ContractRenewal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\RichText;

/**
 * @mixin ContractRenewal
 */
class RenewalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $self = $request->routeIs('self.*');
        $instance = $this->workflow;
        $step = $instance?->status === 'pending' ? $instance->currentStep() : null;
        $employee = $this->employee;

        return [
            'id' => $this->id,
            /** `hr` (started by HR) or `employee` (requested by the employee). */
            'source' => $this->source,
            'source_label' => $this->source === ContractRenewal::FROM_EMPLOYEE ? 'Requested by the employee' : 'Started by HR',
            /** `pending`, `approved` (contract to be created), `completed`, `rejected`, `cancelled`. */
            'status' => $this->status,
            'status_label' => ContractRenewal::STATUSES[$this->status] ?? $this->status,
            'comment' => $this->comment,
            /** The employee's request letter (cleaned HTML). */
            'letter' => $this->letter,
            /** sw or en. */
            'letter_language' => $this->letter_language,
            /** The letter as plain text. */
            'letter_text' => $this->letter ? RichText::text($this->letter) : null,
            /** The statement the employee agreed to, and when. */
            'agreement' => $this->agreement,
            'agreed_at' => $this->agreed_at?->toIso8601String(),
            /** @var array{id: int, name: string}|null */
            'contract_type' => $this->natureOfContract ? ['id' => $this->natureOfContract->id, 'name' => $this->natureOfContract->contract_name] : null,
            'period' => $this->period,
            /** Hidden from the employee while approvers are changing it; shown once final. */
            'basic_salary' => $this->basic_salary === null || ($self && $this->status !== 'completed' && $this->changes()->exists()) ? null : (float) $this->basic_salary,
            /** @var list<array{id: int, name: string, url: string, size: int|null}> */
            'files' => $this->files->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->file_name, 'size' => $f->size,
                'url' => route($self ? 'self.renewals.file' : 'hr.renewals.file', [$this->id, $f->id], false),
            ])->values()->all(),
            /** @var array{id: int, name: string, employee_no: string, department: string|null}|null */
            'employee' => $self || ! $employee ? null : ['id' => $employee->id, 'name' => $employee->full_name, 'employee_no' => $employee->employee_no, 'department' => $employee->department?->name],
            /** Current contract's end date. */
            'contract_end' => $this->contract?->end_date?->toDateString(),
            'requested_by' => $this->requestedBy?->display_name,
            /** The approval step it is waiting for. */
            'waiting_for' => $step?->name,
            /** Approval request, for its page in Approvals. */
            'workflow_id' => $instance?->id,
            'decision_comment' => $this->decision_comment,
            'new_contract_id' => $this->new_contract_id,
            'can_withdraw' => $this->status === 'pending' && ($instance ? $instance->initiated_by === $request->user()?->id : $this->requested_by === $request->user()?->id),
            'created_at' => $this->created_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
