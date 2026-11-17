<?php

namespace Modules\Audit\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Audit\Actions\ManageEngagements;
use Modules\Audit\Actions\ManageQueries;
use Modules\Audit\Models\AuditEngagement;
use Modules\Audit\Models\AuditQuery;
use Modules\Audit\Support\AuditChecks;
use Modules\Audit\Support\Auditors;

/**
 * @tags Audit
 */
class AuditController extends Controller
{
    private function auditor(Request $request): void
    {
        abort_unless($request->user()->can('audit.view'), 403);
    }

    /**
     * The audit start page: checks grouped by area, each with the number of records behind it.
     *
     * @response array{data: array{from: string, to: string, groups: list<array{key: string, label: string, checks: list<array{key: string, label: string, hint: string, count: int, amount: float|null}>}>}, me: array{kind: string, period: array{from: string, to: string}|null, access_until: string|null, firm: string|null, open_queries: int}}
     */
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    #[QueryParameter('threshold', description: 'Amount from which a manual journal counts as large (default 10,000,000).', type: 'number')]
    public function dashboard(Request $request, AuditChecks $checks): JsonResponse
    {
        $this->auditor($request);
        $user = $request->user();
        $e = Auditors::external($user) ? Auditors::engagement($user) : null;
        $period = Auditors::period($user);

        return response()->json([
            'data' => $checks->for($user, $request->query('from', $period[0] ?? null), $request->query('to', $period[1] ?? null), (float) $request->query('threshold', 0))->summary(),
            'me' => ['kind' => Auditors::external($user) ? 'external' : (Auditors::is($user) ? 'internal' : 'other'), 'period' => $period ? ['from' => $period[0], 'to' => $period[1]] : null,
                'access_until' => $e?->access_until->toDateString(), 'firm' => $e?->firm, 'open_queries' => AuditQuery::whereIn('status', ['open', 'answered'])->count()],
        ]);
    }

    /**
     * The records behind one check.
     *
     * @response array{data: list<array{reference: string, description: string, date: string|null, amount: float|null, link: string|null}>, check: array{key: string, label: string, hint: string}}
     */
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    #[QueryParameter('threshold', type: 'number')]
    public function check(Request $request, string $key, AuditChecks $checks): JsonResponse
    {
        $this->auditor($request);
        abort_unless(isset(AuditChecks::CHECKS[$key]), 404);
        [, $label, $hint] = AuditChecks::CHECKS[$key];

        return response()->json(['data' => $checks->for($request->user(), $request->query('from'), $request->query('to'), (float) $request->query('threshold', 0))->rows($key),
            'check' => ['key' => $key, 'label' => $label, 'hint' => $hint]]);
    }

    /**
     * Payroll totals per approved month (no individual pay), for auditors without payslip access.
     *
     * @response array{data: list<array{id: int, name: string, period: string, staff: int, gross: float, paye: float, nssf: float, nhif: float, heslb: float, wcf: float, sdl: float, other: float, net: float, employer_cost: float}>}
     */
    public function payroll(Request $request): JsonResponse
    {
        $this->auditor($request);
        [$from, $to] = Auditors::clamp($request->user(), null, null);
        $sheets = \App\Models\PayrollSheet::where('status', 'approved')->get()
            ->filter(fn ($s) => ! $from || (sprintf('%04d-%02d-01', $s->year, $s->month) >= substr($from, 0, 7).'-01' && sprintf('%04d-%02d-01', $s->year, $s->month) <= $to))
            ->sortBy(fn ($s) => sprintf('%04d%02d', $s->year, $s->month));
        $sums = \App\Models\PayrollSheetDetail::whereIn('payroll_sheet_id', $sheets->pluck('id'))->groupBy('payroll_sheet_id')
            ->selectRaw('payroll_sheet_id, count(*) n, sum(gross_salary) g, sum(paye) paye, sum(nssf) nssf, sum(nssf_employer) nssf_er, sum(nhif) nhif, sum(nhif_employer) nhif_er, sum(heslb) heslb, sum(wcf) wcf, sum(sdl) sdl, sum(total_deduction) d, sum(net_salary) net')
            ->get()->keyBy('payroll_sheet_id');

        return response()->json(['data' => $sheets->map(function ($s) use ($sums) {
            $t = $sums[$s->id] ?? null;
            $v = fn ($k) => round((float) ($t?->{$k} ?? 0), 2);

            return ['id' => $s->id, 'name' => $s->name, 'period' => sprintf('%04d-%02d', $s->year, $s->month), 'staff' => (int) ($t?->n ?? 0), 'gross' => $v('g'), 'paye' => $v('paye'),
                'nssf' => round($v('nssf') + $v('nssf_er'), 2), 'nhif' => round($v('nhif') + $v('nhif_er'), 2), 'heslb' => $v('heslb'), 'wcf' => $v('wcf'), 'sdl' => $v('sdl'),
                'other' => round($v('d') - $v('paye') - $v('nssf') - $v('nhif') - $v('heslb'), 2), 'net' => $v('net'),
                'employer_cost' => round($v('g') + $v('nssf_er') + $v('nhif_er') + $v('wcf') + $v('sdl'), 2)];
        })->values()]);
    }

    /**
     * Audit queries: auditors see all; everyone else sees the ones addressed to them.
     *
     * @response array{data: list<array{id: int, number: string, title: string, question: string, reference: string|null, link: string|null, area: string|null, area_label: string|null, assigned_to: int, assignee: string|null, raised_by: string|null, due_date: string|null, overdue: bool, status: string, status_label: string, response: string|null, responded_by: string|null, responded_at: string|null, closing_note: string|null, created_at: string, can: array{respond: bool, close: bool}}>, areas: list<array{value: string, label: string}>}
     */
    public function queries(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $user->can('audit.view');
        $rows = AuditQuery::with(['assignee', 'raiser', 'responder'])->when(! $all, fn ($q) => $q->where('assigned_to', $user->id))->orderByDesc('id')->limit(1000)->get();

        return response()->json(['data' => $rows->map(fn (AuditQuery $q) => ['id' => $q->id, 'number' => $q->number, 'title' => $q->title, 'question' => $q->question, 'reference' => $q->reference,
            'link' => $q->link, 'area' => $q->area, 'area_label' => $q->area ? (AuditQuery::AREAS[$q->area] ?? $q->area) : null, 'assigned_to' => $q->assigned_to, 'assignee' => $q->assignee?->display_name,
            'raised_by' => $q->raiser?->display_name, 'due_date' => $q->due_date?->toDateString(), 'overdue' => $q->status === 'open' && $q->due_date?->isPast(), 'status' => $q->status,
            'status_label' => AuditQuery::STATUS_LABELS[$q->status], 'response' => $q->response, 'responded_by' => $q->responder?->display_name, 'responded_at' => $q->responded_at?->toIso8601String(),
            'closing_note' => $q->closing_note, 'created_at' => $q->created_at->toIso8601String(),
            'can' => ['respond' => $q->status !== 'closed' && $q->assigned_to === $user->id, 'close' => $q->status !== 'closed' && $user->can('audit.queries')]])->values(),
            'areas' => collect(AuditQuery::AREAS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()]);
    }

    /** @response array{data: list<array{id: int, name: string}>} */
    public function users(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('audit.queries'), 403);

        return response()->json(['data' => User::where('company_id', $request->user()->company_id)->where('is_active', true)->get()
            ->reject(fn ($u) => Auditors::is($u))->map(fn ($u) => ['id' => $u->id, 'name' => $u->display_name])->sortBy('name')->values()]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function storeQuery(Request $request, ManageQueries $actions): JsonResponse
    {
        abort_unless($request->user()->can('audit.queries'), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'question' => ['required', 'string', 'max:5000'], 'reference' => ['nullable', 'string', 'max:200'],
            /** Path in the app of the record asked about, e.g. /payments/vouchers/12. */
            'link' => ['nullable', 'string', 'max:300', 'regex:/^\//'], 'area' => ['nullable', Rule::in(array_keys(AuditQuery::AREAS))], 'assigned_to' => ['required', 'integer'],
            'due_date' => ['nullable', 'date']]);
        $q = $actions->create($data, $request->user());

        return response()->json(['data' => ['id' => $q->id, 'number' => $q->number, 'status' => $q->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function respond(Request $request, AuditQuery $query, ManageQueries $actions): JsonResponse
    {
        $data = $request->validate(['response' => ['required', 'string', 'max:5000']]);
        $q = $actions->respond($query, $data['response'], $request->user());

        return response()->json(['data' => ['id' => $q->id, 'number' => $q->number, 'status' => $q->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function close(Request $request, AuditQuery $query, ManageQueries $actions): JsonResponse
    {
        abort_unless($request->user()->can('audit.queries'), 403);
        $data = $request->validate([
            /** True to send it back for a better answer instead of closing. */
            'reopen' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $q = ($data['reopen'] ?? false) ? $actions->reopen($query, $data['note'] ?? 'Please give more detail.', $request->user()) : $actions->close($query, $data['note'] ?? null, $request->user());

        return response()->json(['data' => ['id' => $q->id, 'number' => $q->number, 'status' => $q->status]]);
    }

    /** @response array{data: list<array{id: int, number: string, firm: string, auditor_name: string, email: string, period_from: string, period_to: string, access_until: string, payslips: bool, scope_note: string|null, status: string, status_label: string, waiting_for: string|null, username: string|null, can: array{withdraw: bool, end: bool}}>} */
    public function engagements(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('audit.engagements'), 403);

        return response()->json(['data' => AuditEngagement::with(['user', 'workflow.steps'])->orderByDesc('id')->get()->map(fn (AuditEngagement $e) => ['id' => $e->id, 'number' => $e->number, 'firm' => $e->firm,
            'auditor_name' => $e->auditor_name, 'email' => $e->email, 'period_from' => $e->period_from->toDateString(), 'period_to' => $e->period_to->toDateString(),
            'access_until' => $e->access_until->toDateString(), 'payslips' => $e->payslips, 'scope_note' => $e->scope_note, 'status' => $e->status,
            'status_label' => AuditEngagement::STATUS_LABELS[$e->status] ?? $e->status, 'waiting_for' => $e->status === 'pending' ? $e->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'username' => $e->user?->username, 'can' => ['withdraw' => $e->status === 'pending' && $e->created_by === $request->user()->id, 'end' => $e->status === 'active']])->values()]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function storeEngagement(Request $request, ManageEngagements $actions): JsonResponse
    {
        abort_unless($request->user()->can('audit.engagements'), 403);
        $data = $request->validate(['firm' => ['required', 'string', 'max:200'], 'auditor_name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:150'],
            /** The financial period being audited; the auditor sees nothing outside it. */
            'period_from' => ['required', 'date'], 'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            /** The account is switched off after this day. */
            'access_until' => ['required', 'date', 'after_or_equal:today'],
            /** Allow individual payslips (otherwise payroll totals only). */
            'payslips' => ['nullable', 'boolean'], 'scope_note' => ['nullable', 'string', 'max:2000']]);
        $e = $actions->create($data, $request->user());

        return response()->json(['data' => ['id' => $e->id, 'number' => $e->number, 'status' => $e->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdrawEngagement(Request $request, AuditEngagement $engagement, ManageEngagements $actions): JsonResponse
    {
        $e = $actions->withdraw($engagement, $request->user());

        return response()->json(['data' => ['id' => $e->id, 'number' => $e->number, 'status' => $e->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function endEngagement(Request $request, AuditEngagement $engagement, ManageEngagements $actions): JsonResponse
    {
        abort_unless($request->user()->can('audit.engagements'), 403);
        $e = $actions->end($engagement);

        return response()->json(['data' => ['id' => $e->id, 'number' => $e->number, 'status' => $e->status]]);
    }
}
