<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Actions\ManageOpeningBalances;
use Modules\Accounting\Http\Requests\OpeningBalanceRequest;
use Modules\Accounting\Http\Requests\OpeningImportRequest;
use Modules\Accounting\Http\Requests\OpeningRowsRequest;
use Modules\Accounting\Models\OpeningBalance;
use Modules\Accounting\Models\OpeningBalanceLine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Accounting: opening balances
 */
class OpeningBalanceController extends Controller
{
    private const TEMPLATES = [
        'accounts' => [['reference', 'account_code', 'debit', 'credit', 'department', 'fund_source', 'description'], [['TB-001', '1100', '25000000', '', '', '', 'Cash at bank'], ['TB-002', '3100', '', '25000000', '', '', 'Retained earnings']]],
        'creditors' => [['reference', 'supplier', 'supplier_tin', 'account_code', 'amount', 'document_date', 'due_date', 'description'], [['BILL-0457', 'Office World Ltd', '111-222-333', '2100', '1250000', '2026-12-15', '2027-01-14', 'Stationery']]],
        'debtors' => [['reference', 'customer', 'customer_tin', 'account_code', 'amount', 'document_date', 'due_date', 'description'], [['INV-0981', 'Jubilee Insurance', '', '', '3400000', '2026-12-20', '2027-01-19', 'December claims']]],
        'breakdowns' => [['reference', 'account_code', 'amount', 'party', 'department', 'description'], [['BRK-001', '2300', '800000', 'Staff welfare fund', 'Administration', 'Held for staff']]],
    ];

    public function __construct(private readonly ManageOpeningBalances $actions) {}

    /**
     * Opening balance batches, with the first fiscal year they belong to.
     *
     * @response array{data: list<array{id: int, number: string, kind: string, kind_label: string, date: string, status: string, status_label: string, rows: int, debit: float, credit: float, created_by: string|null}>, first_year: array{name: string, start_date: string, end_date: string}|null, locked: bool, kinds: list<array{value: string, label: string}>}
     */
    public function index(): JsonResponse
    {
        $first = \Modules\Accounting\Models\FiscalYear::orderBy('start_date')->first();
        $batches = OpeningBalance::with('creator')->withCount('lines')->withSum('lines', 'debit')->withSum('lines', 'credit')->orderByDesc('id')->get();

        return response()->json([
            'data' => $batches->map(fn (OpeningBalance $b) => ['id' => $b->id, 'number' => $b->number, 'kind' => $b->kind, 'kind_label' => OpeningBalance::KINDS[$b->kind] ?? $b->kind,
                'date' => $b->date->toDateString(), 'status' => $b->status, 'status_label' => OpeningBalance::STATUS_LABELS[$b->status] ?? $b->status, 'rows' => (int) $b->lines_count,
                'debit' => round((float) $b->lines_sum_debit, 2), 'credit' => round((float) $b->lines_sum_credit, 2), 'created_by' => $b->creator?->display_name])->values(),
            'first_year' => $first ? ['name' => $first->name, 'start_date' => $first->start_date->toDateString(), 'end_date' => $first->end_date->toDateString()] : null,
            'locked' => \Modules\Accounting\Models\FiscalYear::where('status', '!=', 'open')->exists(),
            'kinds' => collect(OpeningBalance::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ]);
    }

    /**
     * @response array{data: array{id: int, number: string, kind: string, kind_label: string, date: string, notes: string|null, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, journal: array{id: int, reference_no: string|null}|null, debit: float, credit: float, problems: list<string>, lines: list<array{id: int, reference: string, account: string|null, debit: float, credit: float, amount: float, department: string|null, fund_source: string|null, party: string|null, document_date: string|null, due_date: string|null, description: string|null, target: array{type: string, id: int, number: string|null}|null}>, can: array{edit: bool, submit: bool, withdraw: bool, delete: bool}}}
     */
    public function show(Request $request, OpeningBalance $opening): JsonResponse
    {
        $b = $opening->load(['lines.account', 'lines.department', 'lines.fundSource', 'lines.target', 'journal', 'workflow.steps']);
        $suppliers = \Modules\Procurement\Models\Supplier::whereIn('id', $b->lines->pluck('supplier_id')->filter())->pluck('name', 'id');
        $customers = \Modules\Receivables\Models\Customer::whereIn('id', $b->lines->pluck('customer_id')->filter())->pluck('name', 'id');
        $loader = $this->loader($request);

        return response()->json(['data' => [
            'id' => $b->id, 'number' => $b->number, 'kind' => $b->kind, 'kind_label' => OpeningBalance::KINDS[$b->kind] ?? $b->kind, 'date' => $b->date->toDateString(), 'notes' => $b->notes,
            'status' => $b->status, 'status_label' => OpeningBalance::STATUS_LABELS[$b->status] ?? $b->status,
            'waiting_for' => $b->status === 'pending' ? $b->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $b->decision_comment,
            'journal' => $b->journal ? ['id' => $b->journal->id, 'reference_no' => $b->journal->reference_no] : null,
            'debit' => round($b->lines->sum('debit'), 2), 'credit' => round($b->lines->sum('credit'), 2), 'problems' => $b->editable() ? $this->actions->problems($b) : [],
            'lines' => $b->lines->map(fn (OpeningBalanceLine $l) => ['id' => $l->id, 'reference' => $l->reference, 'account' => $l->account?->label(), 'debit' => $l->debit, 'credit' => $l->credit,
                'amount' => $l->amount(), 'department' => $l->department?->name, 'fund_source' => $l->fundSource?->code,
                'party' => $l->party ?? ($l->supplier_id ? $suppliers[$l->supplier_id] ?? null : ($l->customer_id ? $customers[$l->customer_id] ?? null : null)),
                'document_date' => $l->document_date?->toDateString(), 'due_date' => $l->due_date?->toDateString(), 'description' => $l->description,
                'target' => $l->target ? ['type' => $b->kind === 'creditors' ? 'payment-request' : 'invoice', 'id' => $l->target->id, 'number' => $l->target->number ?? null] : null])->values(),
            'can' => ['edit' => $b->editable() && $loader, 'submit' => $b->editable() && $loader, 'withdraw' => $b->status === 'pending' && $b->created_by === $request->user()->id,
                'delete' => $b->status === 'draft' && $loader],
        ]]);
    }

    private function loader(Request $request): bool
    {
        return $request->user()->can('accounting.opening');
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(OpeningBalanceRequest $request): JsonResponse
    {
        $b = $this->actions->create($request->validated(), $request->user());

        return response()->json(['data' => ['id' => $b->id, 'number' => $b->number, 'status' => $b->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(OpeningBalanceRequest $request, OpeningBalance $opening): JsonResponse
    {
        $b = $this->actions->update($opening, $request->validated());

        return response()->json(['data' => ['id' => $b->id, 'number' => $b->number, 'status' => $b->status]]);
    }

    /** @response array{data: array{added: int, results: list<array{row: int, reference: string|null, ok: bool, message: string}>}} */
    public function rows(OpeningRowsRequest $request, OpeningBalance $opening): JsonResponse
    {
        return response()->json(['data' => $this->actions->addRows($opening, $request->validated()['rows'], $request->user())]);
    }

    /** @response array{data: array{added: int, results: list<array{row: int, reference: string|null, ok: bool, message: string}>}} */
    public function import(OpeningImportRequest $request, OpeningBalance $opening): JsonResponse
    {
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), null));
        }
        fclose($handle);

        return response()->json(['data' => $this->actions->addRows($opening, $rows, $request->user())]);
    }

    #[QueryParameter('kind', description: 'accounts, creditors, debtors or breakdowns.', type: 'string', required: true)]
    public function template(Request $request): StreamedResponse
    {
        $kind = array_key_exists((string) $request->query('kind'), self::TEMPLATES) ? (string) $request->query('kind') : 'accounts';
        [$header, $rows] = self::TEMPLATES[$kind];

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "opening-{$kind}-template.csv", ['Content-Type' => 'text/csv']);
    }

    public function destroyRow(OpeningBalance $opening, OpeningBalanceLine $line): JsonResponse
    {
        $this->actions->removeRow($opening, $line);

        return response()->json(null, 204);
    }

    public function destroy(OpeningBalance $opening): JsonResponse
    {
        $this->actions->delete($opening);

        return response()->json(null, 204);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, OpeningBalance $opening): JsonResponse
    {
        $b = $this->actions->submit($opening, $request->user());

        return response()->json(['data' => ['id' => $b->id, 'number' => $b->number, 'status' => $b->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, OpeningBalance $opening): JsonResponse
    {
        $b = $this->actions->withdraw($opening, $request->user());

        return response()->json(['data' => ['id' => $b->id, 'number' => $b->number, 'status' => $b->status]]);
    }

    /**
     * Each control account against the creditors, debtors or breakdowns loaded under it.
     *
     * @response array{data: list<array{account_id: int, code: string, account: string, kind: string, kind_label: string, opening: float, approved: float, in_progress: float, difference: float}>}
     */
    public function reconciliation(): JsonResponse
    {
        return response()->json(['data' => $this->actions->reconciliation()]);
    }
}
