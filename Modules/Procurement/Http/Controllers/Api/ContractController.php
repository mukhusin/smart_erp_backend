<?php

namespace Modules\Procurement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Procurement\Actions\ManageContracts;
use Modules\Procurement\Models\Contract;
use Modules\Procurement\Models\ContractCertificate;
use Modules\Procurement\Models\ContractMilestone;
use Modules\Procurement\Models\ContractProgress;
use Modules\Procurement\Models\ContractVariation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Support\ProcurementAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Procurement: contracts
 */
class ContractController extends Controller
{
    public function __construct(private readonly ProcurementAccess $access, private readonly ManageContracts $actions) {}

    public static function termRules(bool $create): array
    {
        return [
            'title' => ['nullable', 'string', 'max:200'],
            'type' => [$create ? 'required' : 'sometimes', Rule::in(array_keys(Contract::TYPES))],
            'category' => ['nullable', Rule::in(array_keys(Contract::CATEGORIES))],
            'start_date' => [$create ? 'required' : 'sometimes', 'date'],
            'end_date' => [$create ? 'required' : 'sometimes', 'date'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'retention_percent' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'damages_percent_per_day' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'damages_cap_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'security_type' => ['nullable', Rule::in(array_keys(Contract::SECURITY))],
            'security_amount' => ['nullable', 'numeric', 'min:0'],
            'security_reference' => ['nullable', 'string', 'max:100'],
            'security_expiry' => ['nullable', 'date'],
            /** Payment terms: advance, delivery, certificate or retention release milestones. */
            'milestones' => ['nullable', 'array'],
            'milestones.*.name' => ['required', 'string', 'max:200'],
            'milestones.*.kind' => ['required', Rule::in(array_keys(ContractMilestone::KINDS))],
            'milestones.*.amount' => ['required', 'numeric', 'min:0'],
            'milestones.*.due_date' => ['nullable', 'date'],
        ];
    }

    private function buyer(Request $request): void
    {
        abort_unless($this->access->buyer($request->user()), 403);
    }

    /**
     * @response array{data: list<array{id: int, number: string, title: string, supplier: string|null, type_label: string, category: string, value: float, end_date: string, security_expiry: string|null, percent_complete: int, status: string, status_label: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('expiring', description: 'Only contracts or securities ending within the warning days.', type: 'boolean')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $limit = today()->addDays(\Modules\Procurement\Models\ProcurementSetting::current()->expiry_warning_days ?: 30);
        $page = Contract::with('supplier')->inAuditPeriod('start_date')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('expiring'), fn ($q) => $q->where('status', 'active')->where(fn ($q) => $q->whereDate('end_date', '<=', $limit)->orWhereDate('security_expiry', '<=', $limit)))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 200));

        return response()->json(['data' => collect($page->items())->map(fn (Contract $c) => ['id' => $c->id, 'number' => $c->number, 'title' => $c->title, 'supplier' => $c->supplier?->name,
            'type_label' => Contract::TYPES[$c->type] ?? $c->type, 'category' => $c->category, 'value' => $c->value, 'end_date' => $c->end_date->toDateString(),
            'security_expiry' => $c->security_expiry?->toDateString(), 'percent_complete' => (int) $c->percent_complete, 'status' => $c->status, 'status_label' => Contract::STATUS_LABELS[$c->status] ?? $c->status])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    /**
     * @response array{data: array{id: int, number: string, title: string, type: string, type_label: string, category: string, category_label: string, supplier: array{id: int, name: string}, tender: array{id: int, number: string}|null, requisition: array{id: int, number: string, department_id: int}|null, start_date: string, end_date: string, currency: string|null, exchange_rate: float, value: float, retention_percent: float, damages_percent_per_day: float, damages_cap_percent: float, security_type: string|null, security_amount: float|null, security_reference: string|null, security_expiry: string|null, percent_complete: int, status: string, status_label: string, signed_on: string|null, waiting_for: string|null, decision_comment: string|null, close_note: string|null, figures: array{value: float, varied: float, ordered: float, certified: float, retained: float, paid: float, balance: float}, lines: list<array{id: int, description: string, unit: string|null, quantity: float, unit_price: float, amount: float, ordered_quantity: float, remaining: float, budget_line: string|null}>, milestones: list<array{id: int, name: string, kind: string, kind_label: string, amount: float, due_date: string|null, status: string, status_label: string, payment_request: array{id: int, number: string, status: string}|null}>, certificates: list<array{id: int, number: string, date: string, period: string|null, gross: float, retention: float, damages: float, advance_recovery: float, net: float, status: string, status_label: string, has_file: bool, mine: bool, payment_request: array{id: int, number: string, status: string}|null}>, variations: list<array{id: int, number: string, kind: string, kind_label: string, amount: float, new_end_date: string|null, reason: string, status: string, status_label: string, mine: bool}>, progress: list<array{id: int, date: string, kind: string, kind_label: string, percent_complete: int|null, description: string, has_file: bool, by: string|null}>, orders: list<array{id: int, number: string, order_date: string, total: float, status: string}>, can: array{edit: bool, submit: bool, withdraw: bool, order: bool, certify: bool, vary: bool, progress: bool, close: bool}}}
     */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $c = $contract->load(['supplier', 'tender', 'requisition', 'lines.budgetItem', 'milestones', 'certificates', 'variations', 'progress.creator', 'orders', 'workflow.steps']);
        $user = $request->user();
        $buyer = $this->access->buyer($user);
        $requests = \Modules\Payments\Models\PaymentRequest::whereIn('id', $c->milestones->pluck('payment_request_id')->merge($c->certificates->pluck('payment_request_id'))->filter())->get()->keyBy('id');
        $pr = fn ($id) => $id && isset($requests[$id]) ? ['id' => $id, 'number' => $requests[$id]->number, 'status' => $requests[$id]->status] : null;
        $active = $c->status === 'active';

        return response()->json(['data' => [
            'id' => $c->id, 'number' => $c->number, 'title' => $c->title, 'type' => $c->type, 'type_label' => Contract::TYPES[$c->type] ?? $c->type, 'category' => $c->category,
            'category_label' => Contract::CATEGORIES[$c->category] ?? $c->category, 'supplier' => ['id' => $c->supplier->id, 'name' => $c->supplier->name],
            'tender' => $c->tender ? ['id' => $c->tender->id, 'number' => $c->tender->number] : null, 'requisition' => $c->requisition ? ['id' => $c->requisition->id, 'number' => $c->requisition->number, 'department_id' => $c->requisition->department_id] : null,
            'start_date' => $c->start_date->toDateString(), 'end_date' => $c->end_date->toDateString(), 'currency' => $c->currency, 'exchange_rate' => $c->exchange_rate, 'value' => $c->value,
            'retention_percent' => $c->retention_percent, 'damages_percent_per_day' => $c->damages_percent_per_day, 'damages_cap_percent' => $c->damages_cap_percent, 'security_type' => $c->security_type,
            'security_amount' => $c->security_amount, 'security_reference' => $c->security_reference, 'security_expiry' => $c->security_expiry?->toDateString(), 'percent_complete' => (int) $c->percent_complete,
            'status' => $c->status, 'status_label' => Contract::STATUS_LABELS[$c->status] ?? $c->status, 'signed_on' => $c->signed_on?->toDateString(),
            'waiting_for' => $c->status === 'vetting' ? $c->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $c->decision_comment, 'close_note' => $c->close_note,
            'figures' => $c->figures(),
            'lines' => $c->lines->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'unit' => null, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'amount' => $l->amount,
                'ordered_quantity' => $l->ordered_quantity, 'remaining' => $l->remaining(), 'budget_line' => $l->budgetItem?->label()])->values(),
            'milestones' => $c->milestones->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'kind' => $m->kind, 'kind_label' => ContractMilestone::KINDS[$m->kind], 'amount' => $m->amount,
                'due_date' => $m->due_date?->toDateString(), 'status' => $m->status, 'status_label' => ContractMilestone::STATUS_LABELS[$m->status] ?? $m->status, 'payment_request' => $pr($m->payment_request_id)])->values(),
            'certificates' => $c->certificates->map(fn ($x) => ['id' => $x->id, 'number' => $x->number, 'date' => $x->date->toDateString(), 'period' => $x->period, 'gross' => $x->gross, 'retention' => $x->retention,
                'damages' => $x->damages, 'advance_recovery' => $x->advance_recovery, 'net' => $x->net, 'status' => $x->status, 'status_label' => ContractCertificate::STATUS_LABELS[$x->status] ?? $x->status,
                'has_file' => (bool) $x->path, 'mine' => $x->created_by === $user->id, 'payment_request' => $pr($x->payment_request_id)])->values(),
            'variations' => $c->variations->map(fn ($v) => ['id' => $v->id, 'number' => $v->number, 'kind' => $v->kind, 'kind_label' => ContractVariation::KINDS[$v->kind], 'amount' => $v->amount,
                'new_end_date' => $v->new_end_date?->toDateString(), 'reason' => $v->reason, 'status' => $v->status, 'status_label' => ContractVariation::STATUS_LABELS[$v->status] ?? $v->status,
                'mine' => $v->created_by === $user->id])->values(),
            'progress' => $c->progress->map(fn ($p) => ['id' => $p->id, 'date' => $p->date->toDateString(), 'kind' => $p->kind, 'kind_label' => ContractProgress::KINDS[$p->kind] ?? $p->kind,
                'percent_complete' => $p->percent_complete, 'description' => $p->description, 'has_file' => (bool) $p->path, 'by' => $p->creator?->display_name])->values(),
            'orders' => $c->orders->map(fn (PurchaseOrder $o) => ['id' => $o->id, 'number' => $o->number, 'order_date' => $o->order_date->toDateString(), 'total' => (float) $o->total, 'status' => $o->status])->values(),
            'can' => ['edit' => $buyer && in_array($c->status, ['draft', 'rejected'], true), 'submit' => $buyer && in_array($c->status, ['draft', 'rejected'], true),
                'withdraw' => $c->status === 'vetting' && $c->created_by === $user->id, 'order' => $buyer && $active && ! $c->byCertificate(), 'certify' => $buyer && $active && $c->byCertificate(),
                'vary' => $buyer && $active, 'progress' => $buyer && $active, 'close' => $buyer && $active],
        ]]);
    }

    private function done(Contract $c): JsonResponse
    {
        return response()->json(['data' => ['id' => $c->id, 'number' => $c->number, 'status' => $c->fresh()->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(Request $request, Contract $contract): JsonResponse
    {
        $this->buyer($request);

        return $this->done($this->actions->update($contract, $request->validate(self::termRules(false))));
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, Contract $contract): JsonResponse
    {
        $this->buyer($request);

        return $this->done($this->actions->submit($contract, $request->user()));
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, Contract $contract): JsonResponse
    {
        return $this->done($this->actions->withdrawItem($contract, $request->user()));
    }

    /** @response array{data: array{id: int, number: string}} */
    public function order(Request $request, Contract $contract): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['order_date' => ['nullable', 'date'], 'delivery_date' => ['nullable', 'date'], 'store_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.contract_line_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.0001']]);
        $po = $this->actions->order($contract, $data, $request->user());

        return response()->json(['data' => ['id' => $po->id, 'number' => $po->number]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function certificate(Request $request, Contract $contract): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['date' => ['required', 'date'], 'period' => ['nullable', 'string', 'max:100'], 'percent_complete' => ['nullable', 'integer', 'min:0', 'max:100'],
            /** Value of the work certified, before deductions. */
            'gross' => ['required', 'numeric', 'min:0.01'],
            /** Days late, for liquidated damages at the contract rate (capped). */
            'days_late' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'advance_recovery' => ['nullable', 'numeric', 'min:0'], 'contract_milestone_id' => ['nullable', 'integer'], 'description' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:20480']]);
        $cert = $this->actions->certificate($contract, collect($data)->except('file')->all(), $request->file('file'), $request->user());

        return response()->json(['data' => ['id' => $cert->id, 'number' => $cert->number, 'status' => $cert->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdrawCertificate(Request $request, ContractCertificate $certificate): JsonResponse
    {
        $c = $this->actions->withdrawItem($certificate, $request->user());

        return response()->json(['data' => ['id' => $c->id, 'number' => $c->number, 'status' => $c->status]]);
    }

    public function certificateFile(Request $request, ContractCertificate $certificate): StreamedResponse
    {
        abort_unless($this->access->sees($request->user()) && $certificate->path, 404);

        return Storage::disk('local')->download($certificate->path, $certificate->file_name);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function variation(Request $request, Contract $contract): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(ContractVariation::KINDS))],
            /** Change in price; minus for a reduction. */
            'amount' => ['nullable', 'numeric'], 'budget_item_id' => ['nullable', 'integer'], 'new_end_date' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        $v = $this->actions->variation($contract, $data, $request->user());

        return response()->json(['data' => ['id' => $v->id, 'number' => $v->number, 'status' => $v->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdrawVariation(Request $request, ContractVariation $variation): JsonResponse
    {
        $v = $this->actions->withdrawItem($variation, $request->user());

        return response()->json(['data' => ['id' => $v->id, 'number' => $v->number, 'status' => $v->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function payMilestone(Request $request, Contract $contract, ContractMilestone $milestone): JsonResponse
    {
        $this->buyer($request);
        abort_unless($milestone->contract_id === $contract->id, 404);
        $m = $this->actions->payMilestone($milestone, $request->user());

        return response()->json(['data' => ['id' => $m->id, 'status' => $m->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function progress(Request $request, Contract $contract): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['date' => ['required', 'date'], 'kind' => ['required', Rule::in(array_keys(ContractProgress::KINDS))], 'percent_complete' => ['nullable', 'integer', 'min:0', 'max:100'],
            'description' => ['required', 'string', 'max:5000'], 'file' => ['nullable', 'file', 'max:20480']]);
        $this->actions->progress($contract, collect($data)->except('file')->all(), $request->file('file'), $request->user());

        return $this->done($contract);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function close(Request $request, Contract $contract): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000'],
            /** True to end the contract early (any commitment left is released). */
            'terminate' => ['nullable', 'boolean']]);

        return $this->done($this->actions->close($contract, $data['note'], (bool) ($data['terminate'] ?? false), $request->user()));
    }
}
