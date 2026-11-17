<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Actions\ManageJournals;
use Modules\Accounting\Http\Requests\JournalRequest;
use Modules\Accounting\Http\Requests\ReverseJournalRequest;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Services\PostingService;
use Modules\Core\Services\AccessService;

/**
 * @tags Accounting: journals
 */
class JournalController extends Controller
{
    public function __construct(private readonly ManageJournals $journals, private readonly AccessService $access) {}

    /**
     * Journals, newest first.
     *
     * @response array{data: list<array{id: int, reference_no: string|null, date: string, description: string, entry_type: string, type_label: string, status: string, status_label: string, total: float, created_by: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Number or description.', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('entry_type', type: 'string')]
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    #[QueryParameter('sort', description: '`date`, `total`, `reference_no`; prefix `-` for descending.', type: 'string', default: '-date')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $sort = ltrim((string) $request->query('sort', '-date'), '-');
        $sort = in_array($sort, ['date', 'total', 'reference_no'], true) ? $sort : 'date';
        $page = JournalEntry::with('creator')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('reference_no', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('entry_type'), fn ($q, $t) => $q->where('entry_type', $t))
            ->when(\Modules\Audit\Support\Auditors::clamp($request->user(), $request->query('from'), $request->query('to'))[0], fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when(\Modules\Audit\Support\Auditors::clamp($request->user(), $request->query('from'), $request->query('to'))[1], fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->orderBy($sort, str_starts_with((string) $request->query('sort', '-date'), '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (JournalEntry $e) => [
                'id' => $e->id, 'reference_no' => $e->reference_no, 'date' => $e->date->toDateString(), 'description' => $e->description, 'entry_type' => $e->entry_type,
                'type_label' => JournalEntry::TYPES[$e->entry_type] ?? $e->entry_type, 'status' => $e->status, 'status_label' => JournalEntry::STATUS_LABELS[$e->status] ?? $e->status,
                'total' => (float) $e->total, 'created_by' => $e->creator?->display_name,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A journal with its lines, where it came from and what the user can do.
     *
     * @response array{data: array{id: int, reference_no: string|null, date: string, description: string, entry_type: string, type_label: string, status: string, status_label: string, total: float, period: string|null, created_by: string|null, posted_by: string|null, posted_at: string|null, decision_comment: string|null, waiting_for: string|null, reversal_of: array{id: int, reference_no: string|null}|null, reversed_by: array{id: int, reference_no: string|null}|null, source: array{type: string, id: int}|null, lines: list<array{line_no: int, account: array{id: int, code: string, name: string}|null, department: array{id: int, name: string}|null, fund_source: array{id: int, code: string}|null, activity: array{id: int, title: string}|null, description: string|null, debit: float, credit: float}>, can: array{edit: bool, submit: bool, withdraw: bool, reverse: bool, delete: bool}}}
     */
    public function show(Request $request, JournalEntry $journal): JsonResponse
    {
        $period = \Modules\Audit\Support\Auditors::period($request->user());
        abort_if($period && ($journal->date->toDateString() < $period[0] || $journal->date->toDateString() > $period[1]), 404);
        $journal->load(['lines.account', 'lines.department', 'lines.fundSource', 'lines.activity', 'period', 'creator', 'poster', 'reversalOf', 'reversedBy', 'workflow.steps']);
        $user = $request->user();
        $mine = $journal->created_by === $user->id;
        $canJournal = $this->access->can($user, 'accounting.journals');
        $manual = $journal->entry_type === 'manual';

        return response()->json(['data' => [
            'id' => $journal->id, 'reference_no' => $journal->reference_no, 'date' => $journal->date->toDateString(), 'description' => $journal->description,
            'entry_type' => $journal->entry_type, 'type_label' => JournalEntry::TYPES[$journal->entry_type] ?? $journal->entry_type,
            'status' => $journal->status, 'status_label' => JournalEntry::STATUS_LABELS[$journal->status] ?? $journal->status, 'total' => (float) $journal->total,
            'period' => $journal->period?->name, 'created_by' => $journal->creator?->display_name, 'posted_by' => $journal->poster?->display_name,
            'posted_at' => $journal->posted_at?->toIso8601String(), 'decision_comment' => $journal->decision_comment,
            'waiting_for' => $journal->status === 'pending' ? $journal->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'reversal_of' => $journal->reversalOf ? ['id' => $journal->reversalOf->id, 'reference_no' => $journal->reversalOf->reference_no] : null,
            'reversed_by' => $journal->reversedBy ? ['id' => $journal->reversedBy->id, 'reference_no' => $journal->reversedBy->reference_no] : null,
            'source' => $journal->source_type ? ['type' => class_basename($journal->source_type), 'id' => (int) $journal->source_id] : null,
            'lines' => $journal->lines->map(fn (JournalEntryLine $l) => [
                'line_no' => (int) $l->line_no, 'account' => $l->account ? ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name] : null,
                'department' => $l->department ? ['id' => $l->department->id, 'name' => $l->department->name] : null,
                'fund_source' => $l->fundSource ? ['id' => $l->fundSource->id, 'code' => $l->fundSource->code] : null,
                'activity' => $l->activity ? ['id' => $l->activity->id, 'title' => $l->activity->title] : null,
                'description' => $l->description, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit,
            ])->values(),
            'can' => [
                'edit' => $manual && $canJournal && in_array($journal->status, ['draft', 'rejected'], true),
                'submit' => $manual && $canJournal && in_array($journal->status, ['draft', 'rejected'], true),
                'withdraw' => $mine && $journal->status === 'pending',
                'reverse' => $journal->status === 'posted' && $this->access->can($user, 'accounting.manage') && $journal->entry_type !== 'reversal',
                'delete' => $manual && $canJournal && in_array($journal->status, ['draft', 'rejected'], true),
            ],
        ]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function store(JournalRequest $request): JsonResponse
    {
        $e = $this->journals->save($request->validated(), $request->user());

        return response()->json(['data' => ['id' => $e->id, 'status' => $e->status]], 201);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function update(JournalRequest $request, JournalEntry $journal): JsonResponse
    {
        abort_unless($journal->entry_type === 'manual', 403, 'Only manual journals can be edited.');
        $e = $this->journals->save($request->validated(), $request->user(), $journal);

        return response()->json(['data' => ['id' => $e->id, 'status' => $e->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function submit(Request $request, JournalEntry $journal): JsonResponse
    {
        $e = $this->journals->submit($journal, $request->user());

        return response()->json(['data' => ['id' => $e->id, 'status' => $e->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function withdraw(Request $request, JournalEntry $journal): JsonResponse
    {
        abort_unless($journal->created_by === $request->user()->id, 403);
        $e = $this->journals->withdraw($journal, $request->user());

        return response()->json(['data' => ['id' => $e->id, 'status' => $e->status]]);
    }

    /**
     * Reverse a posted journal with an opposite entry on the given date.
     *
     * @response array{data: array{id: int, reference_no: string|null}}
     */
    public function reverse(ReverseJournalRequest $request, JournalEntry $journal, PostingService $posting): JsonResponse
    {
        $d = $request->validated();
        $r = $posting->reverse($journal->load('lines'), $d['date'], $request->user(), $d['reason']);

        return response()->json(['data' => ['id' => $r->id, 'reference_no' => $r->reference_no]]);
    }

    /** @response array{message: string} */
    public function destroy(JournalEntry $journal): JsonResponse
    {
        abort_unless($journal->entry_type === 'manual', 403);
        $this->journals->delete($journal);

        return response()->json(['message' => 'Journal deleted.']);
    }
}
