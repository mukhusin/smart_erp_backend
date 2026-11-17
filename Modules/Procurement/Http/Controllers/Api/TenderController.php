<?php

namespace Modules\Procurement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Procurement\Actions\ManageContracts;
use Modules\Procurement\Actions\ManageTenders;
use Modules\Procurement\Models\ProcurementMethod;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderAward;
use Modules\Procurement\Models\TenderBid;
use Modules\Procurement\Models\TenderComplaint;
use Modules\Procurement\Models\TenderCriterion;
use Modules\Procurement\Models\TenderMember;
use Modules\Procurement\Support\ProcurementAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Procurement: tenders
 */
class TenderController extends Controller
{
    public function __construct(private readonly ProcurementAccess $access, private readonly ManageTenders $actions) {}

    private function buyer(Request $request): void
    {
        abort_unless($this->access->buyer($request->user()), 403);
    }

    /** @response array{data: list<array{id: int, code: string, name: string, sourcing: string, sourcing_label: string, category: string|null, min_amount: float|null, max_amount: float|null, min_bidders: int, evaluation: string, technical_weight: int, pass_mark: int, negotiation: bool, highest_wins: bool, standstill_days: int, needs_reason: bool, is_active: bool}>, options: array{sourcing: list<array{value: string, label: string}>, evaluation: list<array{value: string, label: string}>, categories: list<array{value: string, label: string}>, document_types: list<array{value: string, label: string}>}} */
    public function methods(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        ProcurementMethod::ensureDefaults();
        $opt = fn (array $a) => collect($a)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values();

        return response()->json(['data' => ProcurementMethod::orderBy('min_amount')->orderBy('name')->get()->map(fn ($m) => $this->methodRow($m))->values(),
            'options' => ['sourcing' => $opt(ProcurementMethod::SOURCING), 'evaluation' => $opt(ProcurementMethod::EVALUATION), 'categories' => $opt(ProcurementMethod::CATEGORIES),
                'document_types' => $opt(Tender::DOCUMENT_TYPES)]]);
    }

    private function methodRow(ProcurementMethod $m): array
    {
        return ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'sourcing' => $m->sourcing, 'sourcing_label' => ProcurementMethod::SOURCING[$m->sourcing] ?? $m->sourcing, 'category' => $m->category,
            'min_amount' => $m->min_amount, 'max_amount' => $m->max_amount, 'min_bidders' => (int) $m->min_bidders, 'evaluation' => $m->evaluation, 'technical_weight' => (int) $m->technical_weight,
            'pass_mark' => (int) $m->pass_mark, 'negotiation' => $m->negotiation, 'highest_wins' => $m->highest_wins, 'standstill_days' => (int) $m->standstill_days, 'needs_reason' => $m->needs_reason,
            'is_active' => $m->is_active];
    }

    private function methodRules(?ProcurementMethod $m): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('procurement_methods', 'code')->where('company_id', app(\App\Services\CompanyContext::class)->id())->ignore($m?->id)],
            'name' => ['required', 'string', 'max:150'],
            'sourcing' => ['required', Rule::in(array_keys(ProcurementMethod::SOURCING))],
            'category' => ['nullable', Rule::in(array_keys(ProcurementMethod::CATEGORIES))],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'min_bidders' => ['required', 'integer', 'min:1', 'max:20'],
            'evaluation' => ['required', Rule::in(array_keys(ProcurementMethod::EVALUATION))],
            'technical_weight' => ['nullable', 'integer', 'min:0', 'max:100'],
            'pass_mark' => ['nullable', 'integer', 'min:0', 'max:100'],
            'negotiation' => ['nullable', 'boolean'],
            'highest_wins' => ['nullable', 'boolean'],
            'standstill_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'needs_reason' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @response array{data: array{id: int}} */
    public function storeMethod(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $m = ProcurementMethod::create($request->validate($this->methodRules(null)));

        return response()->json(['data' => ['id' => $m->id]]);
    }

    /** @response array{data: array{id: int}} */
    public function updateMethod(Request $request, ProcurementMethod $method): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $method->update($request->validate($this->methodRules($method)));

        return response()->json(['data' => ['id' => $method->id]]);
    }

    /**
     * @response array{data: list<array{id: int, number: string, title: string, method: string|null, requisition: string|null, department: string|null, closing_at: string, bids: int, status: string, status_label: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $member = TenderMember::where('user_id', $user->id)->pluck('tender_id');
        abort_unless($this->access->sees($user) || $member->isNotEmpty(), 403);
        $page = Tender::with(['method', 'requisition.department'])->withCount('bids')
            ->when(! $this->access->sees($user), fn ($q) => $q->whereIn('id', $member))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 200));

        return response()->json(['data' => collect($page->items())->map(fn (Tender $t) => ['id' => $t->id, 'number' => $t->number, 'title' => $t->title, 'method' => $t->method?->name,
            'requisition' => $t->requisition?->number, 'department' => $t->requisition?->department?->name, 'closing_at' => $t->closing_at->toIso8601String(), 'bids' => (int) $t->bids_count,
            'status' => $t->status, 'status_label' => Tender::STATUS_LABELS[$t->status] ?? $t->status])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    /**
     * A tender. Bid prices stay hidden until the opening, and afterwards are shown only to buyers and to committee members who signed the declaration without a conflict.
     *
     * @response array{data: array{id: int, number: string, title: string, document_type: string, document_type_label: string, instructions: string|null, method_reason: string|null, closing_at: string, validity_days: int, bid_security_amount: float|null, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, cancel_reason: string|null, opened_at: string|null, opening_attendees: string|null, evaluation_summary: string|null, negotiation_minutes: string|null, standstill_until: string|null, method: array{id: int, name: string, evaluation: string, evaluation_label: string, min_bidders: int, technical_weight: int, pass_mark: int, negotiation: bool, highest_wins: bool, standstill_days: int}, requisition: array{id: int, number: string, title: string, department: string|null}, lots: list<string>, lines: list<array{id: int, lot: string, description: string, specification: string|null, unit: string|null, quantity: float, estimated_unit_cost: float}>, criteria: list<array{id: int, name: string, kind: string, weight: float}>, clarifications: list<array{id: int, kind: string, question: string|null, answer: string, closing_at: string|null, created_at: string}>, members: list<array{id: int, user_id: int, name: string|null, role: string, role_label: string, chair: bool, declared: bool, conflict: bool, conflict_note: string|null}>, bids: list<array{id: int, supplier_id: int, supplier: string, received_at: string, currency: string|null, discount_percent: float, valid_until: string|null, bid_security: string|null, documents_complete: bool, missing_documents: string|null, notes: string|null, has_file: bool, read_out_total: float|null, responsive: bool|null, technical_score: float|null, financial_score: float|null, combined_score: float|null, rank: int|null, prices: array<string, float>|null, lots: array<string, float>|null, scores: list<array{tender_criterion_id: int, score: float|null, passed: bool|null, comment: string|null}>}>, ranking: array<string, list<array{bid_id: int, supplier: string, price: float, technical: float|null, financial: float|null, combined: float|null, rank: int}>>|null, awards: list<array{id: int, lot: string, supplier: string, amount: float, letter_sent_at: string|null, accept_by: string|null, accepted_at: string|null, contract: array{id: int, number: string}|null}>, complaints: list<array{id: int, supplier: string|null, received_on: string, description: string, outcome: string, outcome_label: string, action: string|null, decision: string|null}>, me: array{member: bool, declared: bool, conflict: bool, can_score: bool}, can: array{edit: bool, submit: bool, clarify: bool, record_bids: bool, open: bool, manage_team: bool, submit_evaluation: bool, propose_award: bool, end_standstill: bool, respond: bool, contract: bool, complaint: bool, cancel: bool, see_prices: bool}}}
     */
    public function show(Request $request, Tender $tender): JsonResponse
    {
        $user = $request->user();
        $t = $tender->load(['method', 'requisition.department', 'lines.requisitionLine.unit', 'criteria', 'clarifications', 'members.user', 'bids.supplier', 'bids.lines.tenderLine', 'bids.scores',
            'awards.supplier', 'awards.contract', 'complaints.supplier', 'workflow.steps']);
        $buyer = $this->access->buyer($user);
        $me = $t->members->where('user_id', $user->id);
        abort_unless($this->access->sees($user) || $me->isNotEmpty() || ($t->workflow && app(\Modules\Workflow\Services\WorkflowEngine::class)->canAct($user, $t->workflow)), 403);
        $cleared = $me->contains(fn ($m) => $m->cleared());
        $seePrices = $t->isOpened() && ($buyer || $cleared);
        $lots = $t->lots();
        $s = $t->status;

        return response()->json(['data' => [
            'id' => $t->id, 'number' => $t->number, 'title' => $t->title, 'document_type' => $t->document_type, 'document_type_label' => Tender::DOCUMENT_TYPES[$t->document_type] ?? $t->document_type,
            'instructions' => $t->instructions, 'method_reason' => $t->method_reason, 'closing_at' => $t->closing_at->toIso8601String(), 'validity_days' => (int) $t->validity_days,
            'bid_security_amount' => $t->bid_security_amount, 'status' => $s, 'status_label' => Tender::STATUS_LABELS[$s] ?? $s,
            'waiting_for' => in_array($s, ['document_review', 'evaluation_review', 'award_review'], true) ? $t->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'decision_comment' => $t->decision_comment, 'cancel_reason' => $t->cancel_reason, 'opened_at' => $t->opened_at?->toIso8601String(), 'opening_attendees' => $t->opening_attendees,
            'evaluation_summary' => $t->evaluation_summary, 'negotiation_minutes' => $t->negotiation_minutes, 'standstill_until' => $t->standstill_until?->toDateString(),
            'method' => ['id' => $t->method->id, 'name' => $t->method->name, 'evaluation' => $t->method->evaluation, 'evaluation_label' => ProcurementMethod::EVALUATION[$t->method->evaluation],
                'min_bidders' => (int) $t->method->min_bidders, 'technical_weight' => (int) $t->method->technical_weight, 'pass_mark' => (int) $t->method->pass_mark, 'negotiation' => $t->method->negotiation,
                'highest_wins' => $t->method->highest_wins, 'standstill_days' => (int) $t->method->standstill_days],
            'requisition' => ['id' => $t->requisition->id, 'number' => $t->requisition->number, 'title' => $t->requisition->title, 'department' => $t->requisition->department?->name],
            'lots' => $lots,
            'lines' => $t->lines->map(fn ($l) => ['id' => $l->id, 'lot' => $l->lot, 'description' => $l->requisitionLine->description, 'specification' => $l->requisitionLine->specification,
                'unit' => $l->requisitionLine->unit?->code, 'quantity' => $l->quantity, 'estimated_unit_cost' => (float) $l->requisitionLine->estimated_unit_cost])->values(),
            'criteria' => $t->criteria->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'weight' => $c->weight])->values(),
            'clarifications' => $t->clarifications->map(fn ($c) => ['id' => $c->id, 'kind' => $c->kind, 'question' => $c->question, 'answer' => $c->answer, 'closing_at' => $c->closing_at?->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String()])->values(),
            'members' => $t->members->map(fn ($m) => ['id' => $m->id, 'user_id' => $m->user_id, 'name' => $m->user?->display_name, 'role' => $m->role, 'role_label' => TenderMember::ROLES[$m->role] ?? $m->role,
                'chair' => $m->chair, 'declared' => $m->declared_at !== null, 'conflict' => $m->conflict, 'conflict_note' => $buyer ? $m->conflict_note : null])->values(),
            'bids' => $t->bids->map(fn (TenderBid $b) => ['id' => $b->id, 'supplier_id' => $b->supplier_id, 'supplier' => $b->supplier->name, 'received_at' => $b->received_at->toIso8601String(),
                'currency' => $b->currency, 'discount_percent' => $b->discount_percent, 'valid_until' => $b->valid_until?->toDateString(), 'bid_security' => $b->bid_security,
                'documents_complete' => $b->documents_complete, 'missing_documents' => $b->missing_documents, 'notes' => $b->notes, 'has_file' => (bool) $b->path,
                'read_out_total' => $t->isOpened() ? $b->read_out_total : null, 'responsive' => $b->responsive, 'technical_score' => $b->technical_score, 'financial_score' => $b->financial_score,
                'combined_score' => $b->combined_score, 'rank' => $b->rank,
                'prices' => $seePrices ? (object) $b->lines->mapWithKeys(fn ($l) => [(string) $l->tender_line_id => (float) $l->unit_price])->all() : null,
                'lots' => $seePrices ? (object) collect($lots)->filter(fn ($lot) => $b->lines->contains(fn ($l) => $l->tenderLine->lot === $lot))->mapWithKeys(fn ($lot) => [$lot => $b->priceFor($lot)])->all() : null,
                'scores' => $seePrices ? $b->scores->map(fn ($sc) => ['tender_criterion_id' => $sc->tender_criterion_id, 'score' => $sc->score, 'passed' => $sc->passed, 'comment' => $sc->comment])->values() : []])->values(),
            'ranking' => $seePrices && in_array($s, ['opened', 'evaluation_review', 'evaluated', 'award_review', 'standstill', 'awarded', 'contracted'], true) ? (object) $this->actions->ranking($t) : null,
            'awards' => $t->awards->map(fn (TenderAward $a) => ['id' => $a->id, 'lot' => $a->lot, 'supplier' => $a->supplier->name, 'amount' => $a->amount, 'letter_sent_at' => $a->letter_sent_at?->toIso8601String(),
                'accept_by' => $a->accept_by?->toDateString(), 'accepted_at' => $a->accepted_at?->toIso8601String(), 'contract' => $a->contract ? ['id' => $a->contract->id, 'number' => $a->contract->number] : null])->values(),
            'complaints' => $t->complaints->map(fn (TenderComplaint $c) => ['id' => $c->id, 'supplier' => $c->supplier?->name, 'received_on' => $c->received_on->toDateString(), 'description' => $c->description,
                'outcome' => $c->outcome, 'outcome_label' => TenderComplaint::OUTCOMES[$c->outcome], 'action' => $c->action, 'decision' => $c->decision])->values(),
            'me' => ['member' => $me->isNotEmpty(), 'declared' => $me->isNotEmpty() && $me->every(fn ($m) => $m->declared_at !== null), 'conflict' => $me->contains('conflict', true),
                'can_score' => $s === 'opened' && $me->contains(fn ($m) => $m->role === 'evaluation' && $m->cleared())],
            'can' => ['edit' => $buyer && $t->editable(), 'submit' => $buyer && $t->editable(), 'clarify' => $buyer && $s === 'published', 'record_bids' => $buyer && $s === 'published' && $t->closing_at->isFuture(),
                'open' => $buyer && $s === 'published' && $t->closing_at->isPast(), 'manage_team' => $buyer && ! in_array($s, ['contracted', 'cancelled'], true),
                'submit_evaluation' => $buyer && $s === 'opened', 'propose_award' => $buyer && $s === 'evaluated', 'end_standstill' => $buyer && $s === 'standstill' && $t->standstill_until?->lte(today()),
                'respond' => $buyer && $s === 'awarded', 'contract' => $buyer && in_array($s, ['awarded', 'contracted'], true),
                'complaint' => $buyer && in_array($s, ['opened', 'evaluation_review', 'evaluated', 'award_review', 'standstill', 'awarded'], true),
                'cancel' => $buyer && ! in_array($s, ['contracted', 'cancelled'], true) && $t->awards->every(fn ($a) => ! $a->contract), 'see_prices' => $seePrices],
        ]]);
    }

    private function tenderRules(bool $create): array
    {
        return [
            'procurement_method_id' => [$create ? 'required' : 'sometimes', 'integer'],
            'title' => [$create ? 'required' : 'sometimes', 'string', 'max:200'],
            'document_type' => [$create ? 'required' : 'sometimes', Rule::in(array_keys(Tender::DOCUMENT_TYPES))],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'method_reason' => ['nullable', 'string', 'max:2000'],
            'closing_at' => [$create ? 'required' : 'sometimes', 'date'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'bid_security_amount' => ['nullable', 'numeric', 'min:0'],
            /** Requisition lines to tender, each in a lot (default: everything still to order, one lot). */
            'lines' => ['nullable', 'array'],
            'lines.*.purchase_requisition_line_id' => ['required', 'integer'],
            'lines.*.lot' => ['nullable', 'string', 'max:60'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            /** Evaluation criteria: mandatory (pass or fail) or technical (weight; technical weights add up to 100). */
            'criteria' => ['nullable', 'array'],
            'criteria.*.name' => ['required', 'string', 'max:200'],
            'criteria.*.kind' => ['required', Rule::in(array_keys(TenderCriterion::KINDS))],
            'criteria.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(Request $request, PurchaseRequisition $requisition): JsonResponse
    {
        $this->buyer($request);
        $t = $this->actions->create($requisition->load('lines'), $request->validate($this->tenderRules(true)), $request->user());

        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number, 'status' => $t->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $t = $this->actions->update($tender, $request->validate($this->tenderRules(false)));

        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number, 'status' => $t->status]]);
    }

    private function done(Tender $t): JsonResponse
    {
        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number, 'status' => $t->fresh()->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);

        return $this->done($this->actions->submitDocument($tender, $request->user()));
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function clarify(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['kind' => ['required', 'in:clarification,amendment'], 'question' => ['nullable', 'string', 'max:5000'], 'answer' => ['required', 'string', 'max:10000'],
            /** Amendments only: a later closing time. */
            'closing_at' => ['nullable', 'date']]);

        return $this->done($this->actions->clarify($tender, $data, $request->user()));
    }

    /** @response array{data: array{id: int}} */
    public function recordBid(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'received_at' => ['nullable', 'date'], 'currency' => ['nullable', 'string', 'size:3'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'valid_until' => ['nullable', 'date'], 'bid_security' => ['nullable', 'string', 'max:200'],
            'documents_complete' => ['nullable', 'boolean'], 'missing_documents' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:500'],
            /** Unit price per tender line; a bid prices every line of each lot it bids for. Sealed until the opening. */
            'lines' => ['required', 'array'], 'lines.*.tender_line_id' => ['required', 'integer'], 'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'file' => ['nullable', 'file', 'max:20480']]);
        $bid = $this->actions->recordBid($tender, collect($data)->except('file')->all(), $request->file('file'));

        return response()->json(['data' => ['id' => $bid->id]]);
    }

    public function bidFile(Request $request, Tender $tender, TenderBid $bid): StreamedResponse
    {
        abort_unless($bid->tender_id === $tender->id && $bid->path && $tender->isOpened() && ($this->access->buyer($request->user()) || $this->actions->member($tender, $request->user())?->cleared()), 404);

        return Storage::disk('local')->download($bid->path, $bid->file_name);
    }

    /** @response array{data: array{id: int}} */
    public function addMember(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'role' => ['required', Rule::in(array_keys(TenderMember::ROLES))], 'chair' => ['nullable', 'boolean']]);
        abort_unless(User::where('company_id', app(\App\Services\CompanyContext::class)->id())->whereKey($data['user_id'])->exists(), 422);

        return response()->json(['data' => ['id' => $this->actions->addMember($tender, $data)->id]]);
    }

    public function removeMember(Request $request, Tender $tender, TenderMember $member): JsonResponse
    {
        $this->buyer($request);
        abort_unless($member->tender_id === $tender->id, 404);
        $this->actions->removeMember($member);

        return response()->json(null, 204);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function declare(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            /** I declare I have no conflict of interest with any bidder and will keep the bids confidential (false), or I have a conflict (true, describe it). */
            'conflict' => ['required', 'boolean'], 'conflict_note' => ['nullable', 'string', 'max:500']]);
        $this->actions->declare($tender, $request->user(), (bool) $data['conflict'], $data['conflict_note'] ?? null);

        return $this->done($tender);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function open(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['attendees' => ['nullable', 'string', 'max:5000']]);

        return $this->done($this->actions->open($tender, $data, $request->user()));
    }

    /** @response array{data: array{id: int}} */
    public function score(Request $request, Tender $tender, TenderBid $bid): JsonResponse
    {
        abort_unless($bid->tender_id === $tender->id, 404);
        $data = $request->validate(['scores' => ['required', 'array'], 'scores.*.tender_criterion_id' => ['required', 'integer'], 'scores.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'scores.*.passed' => ['nullable', 'boolean'], 'scores.*.comment' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => ['id' => $this->actions->score($bid, $data['scores'], $request->user())->id]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submitEvaluation(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['summary' => ['nullable', 'string', 'max:10000'], 'negotiation_minutes' => ['nullable', 'string', 'max:10000']]);

        return $this->done($this->actions->submitEvaluation($tender, $data, $request->user()));
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function award(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['awards' => ['required', 'array', 'min:1'], 'awards.*.lot' => ['required', 'string'], 'awards.*.tender_bid_id' => ['required', 'integer'],
            /** Only after a negotiation the method allows. */
            'awards.*.amount' => ['nullable', 'numeric', 'min:0']]);

        return $this->done($this->actions->proposeAward($tender, $data['awards'], $request->user()));
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function endStandstill(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);

        return $this->done($this->actions->endStandstill($tender));
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function respond(Request $request, Tender $tender, TenderAward $award): JsonResponse
    {
        $this->buyer($request);
        abort_unless($award->tender_id === $tender->id, 404);
        $data = $request->validate(['accepted' => ['required', 'boolean']]);
        $this->actions->respond($award, (bool) $data['accepted']);

        return $this->done($tender);
    }

    /** @response array{data: array{id: int, number: string}} */
    public function contract(Request $request, Tender $tender, TenderAward $award, ManageContracts $contracts): JsonResponse
    {
        $this->buyer($request);
        abort_unless($award->tender_id === $tender->id, 404);
        $data = $request->validate(ContractController::termRules(true));
        $c = $contracts->fromAward($award, $data, $request->user());

        return response()->json(['data' => ['id' => $c->id, 'number' => $c->number]]);
    }

    /** @response array{data: array{id: int}} */
    public function complaint(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['supplier_id' => ['nullable', 'integer'], 'received_on' => ['nullable', 'date'], 'description' => ['required', 'string', 'max:5000']]);

        return response()->json(['data' => ['id' => $this->actions->complaint($tender, $data, $request->user())->id]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function decideComplaint(Request $request, Tender $tender, TenderComplaint $complaint): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage') && $complaint->tender_id === $tender->id, 403);
        $data = $request->validate(['outcome' => ['required', 'in:dismissed,upheld'],
            /** Upheld: reopen the evaluation or cancel the tender. */
            'action' => ['nullable', 'in:reopen,cancel'], 'decision' => ['required', 'string', 'max:5000']]);
        $this->actions->decideComplaint($complaint, $data);

        return $this->done($tender);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function cancel(Request $request, Tender $tender): JsonResponse
    {
        $this->buyer($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->done($this->actions->cancel($tender, $data['reason']));
    }

    /** @response array{data: array{suppliers: list<array{id: int, name: string}>, users: list<array{id: int, name: string}>, methods: list<array{id: int, code: string, name: string, sourcing: string, min_amount: float|null, max_amount: float|null}>, document_types: list<array{value: string, label: string}>}} */
    public function lookups(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        ProcurementMethod::ensureDefaults();

        return response()->json(['data' => [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name'])->filter(fn ($s) => $s->usable())->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'users' => User::where('company_id', app(\App\Services\CompanyContext::class)->id())->where('is_active', true)->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->display_name])->sortBy('name')->values(),
            'methods' => ProcurementMethod::where('is_active', true)->whereIn('sourcing', ['tender', 'single'])->orderBy('name')->get()
                ->map(fn ($m) => ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'sourcing' => $m->sourcing, 'min_amount' => $m->min_amount, 'max_amount' => $m->max_amount])->values(),
            'document_types' => collect(Tender::DOCUMENT_TYPES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ]]);
    }
}
