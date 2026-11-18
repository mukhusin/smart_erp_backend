<?php

namespace Modules\Payments\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Actions\ManageRetirements;
use Modules\Payments\Http\Requests\AttachmentRequest;
use Modules\Payments\Http\Requests\RetirementRequest;
use Modules\Payments\Models\ImprestRetirement;
use Modules\Payments\Models\PaymentAttachment;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Support\PaymentAccess;
use Modules\Payments\Support\PaymentFiles;
use Modules\Workflow\Services\WorkflowEngine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Payments: imprest retirement
 */
class ImprestRetirementController extends Controller
{
    public function __construct(private readonly PaymentAccess $access, private readonly ManageRetirements $retirements) {}

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(RetirementRequest $request, PaymentRequest $paymentRequest): JsonResponse
    {
        abort_unless($this->access->canRetire($request->user(), $paymentRequest), 403, 'Only the holder, the requester or finance can retire this imprest.');
        $ret = $this->retirements->save($paymentRequest, null, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $ret->id, 'number' => $ret->number, 'status' => $ret->status]], 201);
    }

    /**
     * An imprest retirement with its lines, receipts and what the user can do.
     *
     * @response array{data: array{id: int, number: string, date: string, notes: string|null, status: string, status_label: string, advanced: float, spent: float, returned: float, cash_bank_account_id: int|null, bank: string|null, return_reference: string|null, decision_comment: string|null, waiting_for: string|null, journal: array{id: int, reference_no: string}|null, prepared_by: string|null, request: array{id: int, number: string, title: string, holder: string|null, retire_by: string|null, lines: list<array{budget_item_id: int|null, label: string, amount: float}>}, lines: list<array{id: int, budget_item_id: int, label: string, description: string|null, amount: float, input_vat: float, vat_invoice_no: string|null}>, attachments: list<array{id: int, name: string, size: int, uploaded_by: string|null, created_at: string}>, can: array{edit: bool, submit: bool, withdraw: bool, delete: bool}}}
     */
    public function show(Request $request, ImprestRetirement $retirement): JsonResponse
    {
        $ret = $retirement->load(['lines.item.account', 'lines.item.subActivity', 'lines.item.activity', 'attachments.uploader', 'bank', 'journal', 'preparer', 'workflow.steps',
            'request.lines.item.account', 'request.lines.item.subActivity', 'request.lines.item.activity', 'request.payees']);
        $user = $request->user();
        $this->authorizeView($user, $ret);
        $r = $ret->request;
        $mine = $ret->prepared_by === $user->id || $this->access->canRetire($user, $r);

        return response()->json(['data' => [
            'id' => $ret->id, 'number' => $ret->number, 'date' => $ret->date->toDateString(), 'notes' => $ret->notes, 'status' => $ret->status,
            'status_label' => ImprestRetirement::STATUS_LABELS[$ret->status] ?? $ret->status, 'advanced' => $ret->advanced, 'spent' => $ret->spent, 'returned' => $ret->returned,
            'cash_bank_account_id' => $ret->cash_bank_account_id, 'bank' => $ret->bank?->name, 'return_reference' => $ret->return_reference, 'decision_comment' => $ret->decision_comment,
            'waiting_for' => $ret->status === 'pending' ? $ret->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'journal' => $ret->journal ? ['id' => $ret->journal->id, 'reference_no' => $ret->journal->reference_no] : null, 'prepared_by' => $ret->preparer?->display_name,
            'request' => ['id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'holder' => $r->payees->first()?->name, 'retire_by' => $r->retire_by?->toDateString(),
                'lines' => $r->lines->map(fn ($l) => ['budget_item_id' => $l->budget_item_id, 'label' => $l->item?->label() ?? ($l->description ?: '—'), 'amount' => $l->amount])->values()],
            'lines' => $ret->lines->map(fn ($l) => ['id' => $l->id, 'budget_item_id' => $l->budget_item_id, 'label' => $l->item?->label() ?? '—', 'description' => $l->description, 'amount' => $l->amount,
                'input_vat' => (float) $l->input_vat, 'vat_invoice_no' => $l->vat_invoice_no])->values(),
            'attachments' => $ret->attachments->map(fn (PaymentAttachment $a) => ['id' => $a->id, 'name' => $a->name, 'size' => (int) $a->size, 'uploaded_by' => $a->uploader?->display_name,
                'created_at' => $a->created_at->toIso8601String()])->values(),
            'can' => ['edit' => $ret->editable() && $mine, 'submit' => $ret->editable() && $mine, 'withdraw' => $ret->status === 'pending' && $ret->prepared_by === $user->id,
                'delete' => $ret->editable() && $mine],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(RetirementRequest $request, ImprestRetirement $retirement): JsonResponse
    {
        abort_unless($this->access->canRetire($request->user(), $retirement->request), 403);
        $ret = $this->retirements->save($retirement->request, $retirement, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $ret->id, 'number' => $ret->number, 'status' => $ret->status]]);
    }

    public function destroy(Request $request, ImprestRetirement $retirement): JsonResponse
    {
        abort_unless($this->access->canRetire($request->user(), $retirement->request), 403);
        $this->retirements->delete($retirement);

        return response()->json(['message' => 'Retirement deleted.']);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, ImprestRetirement $retirement): JsonResponse
    {
        abort_unless($this->access->canRetire($request->user(), $retirement->request), 403);
        $ret = $this->retirements->submit($retirement, $request->user());

        return response()->json(['data' => ['id' => $ret->id, 'number' => $ret->number, 'status' => $ret->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, ImprestRetirement $retirement): JsonResponse
    {
        $ret = $this->retirements->withdraw($retirement, $request->user());

        return response()->json(['data' => ['id' => $ret->id, 'number' => $ret->number, 'status' => $ret->status]]);
    }

    /** @response array{data: array{id: int, name: string, size: int}} */
    public function attach(AttachmentRequest $request, ImprestRetirement $retirement): JsonResponse
    {
        abort_unless($this->access->canRetire($request->user(), $retirement->request), 403);
        $a = $this->retirements->attach($retirement, $request->file('file'), $request->user());

        return response()->json(['data' => ['id' => $a->id, 'name' => $a->name, 'size' => (int) $a->size]], 201);
    }

    public function file(Request $request, ImprestRetirement $retirement, PaymentAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->imprest_retirement_id === $retirement->id, 404);
        $this->authorizeView($request->user(), $retirement);

        return PaymentFiles::response($attachment->path, $attachment->name);
    }

    public function detach(Request $request, ImprestRetirement $retirement, PaymentAttachment $attachment): JsonResponse
    {
        abort_unless($attachment->imprest_retirement_id === $retirement->id, 404);
        abort_unless($this->access->canRetire($request->user(), $retirement->request), 403);
        $this->retirements->detach($retirement, $attachment);

        return response()->json(['message' => 'Receipt removed.']);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function recover(Request $request, PaymentRequest $paymentRequest, \Modules\Payments\Actions\ManageRecoveries $recoveries): JsonResponse
    {
        abort_unless($this->access->finance($request->user()), 403, 'Only finance can recover an imprest from salary.');
        $data = $request->validate([
            /** Number of monthly deductions. */
            'months' => ['required', 'integer', 'min:1', 'max:24'],
            /** First payroll month, YYYY-MM. */
            'start_month' => ['required', 'date_format:Y-m'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $rec = $recoveries->start($paymentRequest, (int) $data['months'], $data['start_month'], $data['reason'] ?? null, $request->user());

        return response()->json(['data' => ['id' => $rec->id, 'status' => $rec->status]], 201);
    }

    /** @response array{message: string} */
    public function cancelRecovery(Request $request, \Modules\Payments\Models\ImprestRecovery $recovery, \Modules\Payments\Actions\ManageRecoveries $recoveries): JsonResponse
    {
        abort_unless($this->access->finance($request->user()), 403);
        $recoveries->cancel($recovery, $request->user());

        return response()->json(['message' => 'Recovery cancelled; the imprest is waiting for retirement again.']);
    }

    private function authorizeView(User $user, ImprestRetirement $ret): void
    {
        $r = $ret->request;
        $instance = $ret->workflow;
        $approver = $instance && ($instance->steps()->where('acted_by', $user->id)->exists() || app(WorkflowEngine::class)->canAct($user, $instance));
        abort_unless($ret->prepared_by === $user->id || $this->access->canRetire($user, $r) || $this->access->canView($user, $r->department_id) || $approver, 403);
    }
}
