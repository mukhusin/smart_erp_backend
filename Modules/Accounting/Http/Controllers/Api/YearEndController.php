<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Http\Requests\YearEndSettingsRequest;
use Modules\Accounting\Http\Requests\YearReopenRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\FiscalYearStatement;
use Modules\Accounting\Services\YearEndClose;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Accounting: year end
 */
class YearEndController extends Controller
{
    public function __construct(private readonly YearEndClose $close) {}

    /**
     * Every fiscal year with its close state, the checks for the next year to close and the retained earnings account.
     *
     * @response array{data: list<array{id: int, name: string, start_date: string, end_date: string, status: string, status_label: string, closed_at: string|null, waiting_for: string|null, decision_comment: string|null, closing_journal: array{id: int, reference_no: string|null}|null, statements: list<array{report: string, title: string}>}>, next: array{id: int, name: string, preview: array{checks: list<array{key: string, label: string, ok: bool, detail: string|null}>, revenue: float, expense: float, result: float, retained_account: string|null, next_year: string|null, can_close: bool}}|null, retained_earnings_account_id: int|null, equity_accounts: list<array{id: int, code: string, name: string}>}
     */
    public function index(): JsonResponse
    {
        $years = FiscalYear::with(['closingJournal', 'statements', 'workflow.steps'])->orderByDesc('start_date')->get();
        $next = $years->sortBy('start_date')->first(fn ($y) => $y->status !== 'closed' && $y->end_date->lt(today()->addMonths(3)));

        return response()->json([
            'data' => $years->map(fn (FiscalYear $y) => ['id' => $y->id, 'name' => $y->name, 'start_date' => $y->start_date->toDateString(), 'end_date' => $y->end_date->toDateString(),
                'status' => $y->status, 'status_label' => FiscalYear::STATUS_LABELS[$y->status] ?? $y->status, 'closed_at' => $y->closed_at?->toIso8601String(),
                'waiting_for' => $y->status === 'closing' ? $y->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $y->decision_comment,
                'closing_journal' => $y->closingJournal ? ['id' => $y->closingJournal->id, 'reference_no' => $y->closingJournal->reference_no] : null,
                'statements' => $y->statements->sortBy(fn ($s) => array_search($s->report, YearEndClose::STATEMENTS, true))->map(fn ($s) => ['report' => $s->report, 'title' => $s->title])->values()])->values(),
            'next' => $next ? ['id' => $next->id, 'name' => $next->name, 'preview' => $this->close->preview($next)] : null,
            'retained_earnings_account_id' => AccountingSetting::current()->retained_earnings_account_id,
            'equity_accounts' => Account::where('type', 'equity')->where('is_active', true)->where('is_postable', true)->orderBy('code')->get()->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values(),
        ]);
    }

    /** @response array{data: array{retained_earnings_account_id: int}} */
    public function settings(YearEndSettingsRequest $request): JsonResponse
    {
        $id = (int) $request->validated()['retained_earnings_account_id'];
        $account = Account::where('type', 'equity')->where('is_postable', true)->find($id);
        if (! $account) {
            throw ValidationException::withMessages(['retained_earnings_account_id' => 'Choose an equity account that takes postings.']);
        }
        tap(AccountingSetting::current(), fn ($s) => $s->fill(['retained_earnings_account_id' => $account->id])->save());

        return response()->json(['data' => ['retained_earnings_account_id' => $account->id]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function close(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        $y = $this->close->request($fiscalYear, $request->user());

        return response()->json(['data' => ['id' => $y->id, 'status' => $y->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function withdraw(Request $request, FiscalYear $fiscalYear, WorkflowEngine $workflow): JsonResponse
    {
        if ($fiscalYear->status !== 'closing' || $fiscalYear->close_requested_by !== $request->user()->id) {
            throw ValidationException::withMessages(['year' => 'Only the person who asked to close the year can withdraw it while it waits.']);
        }
        $instance = $fiscalYear->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $workflow->cancel($instance, $instance->initiator ?? $request->user()) : $this->close->rejected($fiscalYear, null, true);

        return response()->json(['data' => ['id' => $fiscalYear->id, 'status' => $fiscalYear->refresh()->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function reopen(YearReopenRequest $request, FiscalYear $fiscalYear): JsonResponse
    {
        $y = $this->close->reopen($fiscalYear, $request->user(), $request->validated()['reason']);

        return response()->json(['data' => ['id' => $y->id, 'status' => $y->status]]);
    }

    /**
     * A statement of a closed year exactly as it was when the year was closed.
     *
     * @response array{data: array{key: string, title: string, subtitle: string|null, year: string, closed_at: string|null, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, total: int}}
     */
    public function statement(FiscalYear $fiscalYear, string $report): JsonResponse
    {
        $s = FiscalYearStatement::where('fiscal_year_id', $fiscalYear->id)->where('report', $report)->firstOrFail();

        return response()->json(['data' => ['key' => $s->report, 'title' => $s->title, 'subtitle' => $s->subtitle, 'year' => $fiscalYear->name, 'closed_at' => $fiscalYear->closed_at?->toIso8601String(),
            'columns' => $s->columns, 'rows' => $s->rows, 'total' => count($s->rows)]]);
    }
}
