<?php

namespace Modules\Audit\Support;

use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The audit start page: each check lists the records behind it (reference, what, date, amount, link) so a figure
 * always opens its evidence. Dates are limited to the audited period for external auditors.
 */
class AuditChecks
{
    public const GROUPS = [
        'ledger' => 'Ledger health', 'duties' => 'Separation of duties', 'payments' => 'Payment risks', 'procurement' => 'Procurement',
        'stores' => 'Stores and assets', 'access' => 'System access', 'year_end' => 'Year end',
    ];

    public const CHECKS = [
        'ledger_integrity' => ['ledger', 'Ledger integrity issues', 'Unbalanced journals, postings to heading accounts or outside a fiscal period.'],
        'closed_period' => ['ledger', 'Posted into a closed period', 'Journals posted after their period was closed (for example during a year reopen).'],
        'backdated' => ['ledger', 'Back-dated journals', 'Journals dated more than 30 days before they were posted.'],
        'large_manual' => ['ledger', 'Large manual journals', 'Manual journals at or above the amount chosen.'],
        'reversals' => ['ledger', 'Reversed journals', 'Journals reversed in the period.'],
        'self_approved' => ['duties', 'Approved by the person who asked', 'Approval steps acted on by the requester (should be none).'],
        'over_budget' => ['duties', 'Approved over the budget', 'Payment requests and requisitions approved although the budget line was short.'],
        'duplicates' => ['payments', 'Possible duplicate payments', 'Same payee and net amount on vouchers within 7 days.'],
        'no_attachment' => ['payments', 'Vouchers without supporting documents', 'Approved or paid vouchers whose request has no attachment.'],
        'new_bank_paid' => ['payments', 'Paid soon after a bank change', 'Payments to a supplier account approved less than 30 days before.'],
        'supplier_employee_bank' => ['payments', 'Supplier and employee share a bank account', 'Supplier bank account numbers that are also an employee\'s salary account.'],
        'single_source' => ['procurement', 'Single-source purchases', 'Orders and tenders made by single source.'],
        'few_bids' => ['procurement', 'Fewer bids than required', 'Opened tenders with fewer bids than the method requires.'],
        'above_estimate' => ['procurement', 'Awards above the estimate', 'Tender awards higher than the requisition estimate.'],
        'variations_near_limit' => ['procurement', 'Contracts near the variation limit', 'Running contracts whose increases reached 80% of the allowed limit.'],
        'complaints' => ['procurement', 'Tender complaints', 'Complaints received from bidders.'],
        'count_losses' => ['stores', 'Stock count losses', 'Posted stock counts with items short.'],
        'disposal_losses' => ['stores', 'Assets disposed of at a loss', 'Approved disposals below book value.'],
        'leaver_assets' => ['stores', 'Assets held by staff who left', 'Assets still assigned to employees who have left.'],
        'admins' => ['access', 'Users with administration rights', 'Users who can manage users, roles or workflows.'],
        'access_changes' => ['access', 'Access changes (90 days)', 'Roles and permissions given or removed recently.'],
        'handovers' => ['access', 'Active role handovers', 'Users acting with someone else\'s role.'],
        'leaver_accounts' => ['access', 'Leavers with active accounts', 'Employees who have left but can still log in.'],
        'open_years' => ['year_end', 'Years past their end, not closed', 'Fiscal years whose end date has passed.'],
        'opening_differences' => ['year_end', 'Opening balance differences', 'Control accounts whose detail does not agree with the opening balance.'],
        'pending_approvals' => ['year_end', 'Waiting for approval', 'Requests of any kind still waiting for an approver.'],
    ];

    private ?string $from = null;

    private ?string $to = null;

    private float $threshold = 10000000;

    public function __construct(private readonly CompanyContext $context) {}

    public function for(?User $user, ?string $from, ?string $to, ?float $threshold = null): self
    {
        [$this->from, $this->to] = Auditors::clamp($user, $from, $to);
        $this->from ??= today()->startOfYear()->toDateString();
        $this->to ??= today()->toDateString();
        $this->threshold = $threshold ?: 10000000;

        return $this;
    }

    /** @return array{from: string, to: string, groups: list<array{key: string, label: string, checks: list<array{key: string, label: string, hint: string, count: int, amount: float|null}>}>} */
    public function summary(): array
    {
        $groups = [];
        foreach (self::GROUPS as $g => $label) {
            $checks = [];
            foreach (self::CHECKS as $key => [$group, $name, $hint]) {
                if ($group !== $g) {
                    continue;
                }
                $rows = $this->rows($key);
                $amounts = array_filter(array_column($rows, 'amount'), fn ($a) => $a !== null);
                $checks[] = ['key' => $key, 'label' => $name, 'hint' => $hint, 'count' => count($rows), 'amount' => $amounts ? round(array_sum($amounts), 2) : null];
            }
            $groups[] = ['key' => $g, 'label' => $label, 'checks' => $checks];
        }

        return ['from' => $this->from, 'to' => $this->to, 'groups' => $groups];
    }

    /** @return list<array{reference: string, description: string, date: string|null, amount: float|null, link: string|null}> */
    public function rows(string $key): array
    {
        $method = 'check'.str_replace('_', '', ucwords($key, '_'));
        abort_unless(isset(self::CHECKS[$key]) && method_exists($this, $method), 404);

        return array_slice(array_values($this->{$method}()), 0, 500);
    }

    private function row(string $ref, string $what, $date, ?float $amount, ?string $link): array
    {
        return ['reference' => $ref, 'description' => $what, 'date' => $date ? Carbon::parse($date)->toDateString() : null, 'amount' => $amount !== null ? round($amount, 2) : null, 'link' => $link];
    }

    private function company(): int
    {
        return (int) $this->context->id();
    }

    private function journals()
    {
        return DB::table('journal_entries')->where('journal_entries.company_id', $this->company())->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereBetween('journal_entries.date', [$this->from, $this->to]);
    }

    private function checkLedgerIntegrity(): array
    {
        $r = app(\Modules\Reporting\Support\FinanceReports::class)->build('ledger-check', ['from' => $this->from, 'to' => $this->to]);

        return array_map(fn ($x) => $this->row((string) $x['number'], $x['issue'], $x['date'] ? Carbon::parse($x['date']) : null, $x['difference'] ?? null, null), $r['rows']);
    }

    private function checkClosedPeriod(): array
    {
        return $this->journals()->join('fiscal_periods as p', 'p.id', '=', 'journal_entries.fiscal_period_id')->where('p.status', 'closed')->whereColumn('journal_entries.posted_at', '>', 'p.closed_at')
            ->get(['journal_entries.id', 'reference_no', 'description', 'journal_entries.date', 'total'])
            ->map(fn ($j) => $this->row($j->reference_no, $j->description, $j->date, (float) $j->total, "/accounting/journals/{$j->id}"))->all();
    }

    private function checkBackdated(): array
    {
        return $this->journals()->whereNotIn('entry_type', ['opening', 'closing', 'depreciation'])->whereNotNull('posted_at')
            ->get(['id', 'reference_no', 'description', 'date', 'posted_at', 'total'])->filter(fn ($j) => Carbon::parse($j->date)->diffInDays(Carbon::parse($j->posted_at), false) > 30)
            ->values()->map(fn ($j) => $this->row($j->reference_no, $j->description.' (posted '.Carbon::parse($j->posted_at)->format('d M Y').')', $j->date, (float) $j->total, "/accounting/journals/{$j->id}"))->all();
    }

    private function checkLargeManual(): array
    {
        return $this->journals()->where('entry_type', 'manual')->where('total', '>=', $this->threshold)->orderByDesc('total')->get(['id', 'reference_no', 'description', 'date', 'total'])
            ->map(fn ($j) => $this->row($j->reference_no, $j->description, $j->date, (float) $j->total, "/accounting/journals/{$j->id}"))->all();
    }

    private function checkReversals(): array
    {
        return $this->journals()->where('entry_type', 'reversal')->get(['id', 'reference_no', 'description', 'date', 'total'])
            ->map(fn ($j) => $this->row($j->reference_no, $j->description, $j->date, (float) $j->total, "/accounting/journals/{$j->id}"))->all();
    }

    private function checkSelfApproved(): array
    {
        return DB::table('workflow_instance_steps as s')->join('workflow_instances as i', 'i.id', '=', 's.workflow_instance_id')->where('i.company_id', $this->company())
            ->whereColumn('s.acted_by', 'i.initiated_by')->where('s.status', 'approved')->whereBetween(DB::raw('DATE(s.acted_at)'), [$this->from, $this->to])
            ->get(['i.id', 'i.process', 's.name', 's.acted_at', 'i.amount'])
            ->map(fn ($r) => $this->row("{$r->process} #{$r->id}", "Step '{$r->name}' approved by the requester", $r->acted_at, $r->amount !== null ? (float) $r->amount : null, null))->all();
    }

    private function checkOverBudget(): array
    {
        $pay = DB::table('payment_requests')->where('company_id', $this->company())->where('over_budget', true)->whereIn('status', ['approved', 'vouchered', 'paid'])
            ->whereBetween(DB::raw('DATE(decided_at)'), [$this->from, $this->to])->get(['id', 'number', 'title', 'decided_at', 'total'])
            ->map(fn ($r) => $this->row($r->number, $r->title, $r->decided_at, (float) $r->total, "/payments/requests/{$r->id}"));
        $req = DB::table('purchase_requisitions')->where('company_id', $this->company())->where('over_budget', true)->whereIn('status', ['approved', 'ordered'])
            ->whereBetween(DB::raw('DATE(decided_at)'), [$this->from, $this->to])->get(['id', 'number', 'title', 'decided_at', 'total'])
            ->map(fn ($r) => $this->row($r->number, $r->title, $r->decided_at, (float) $r->total, "/procurement/requisitions/{$r->id}"));

        return $pay->merge($req)->all();
    }

    private function vouchers()
    {
        return DB::table('payment_vouchers as v')->join('payment_requests as r', 'r.id', '=', 'v.payment_request_id')->where('v.company_id', $this->company())
            ->whereIn('v.status', ['approved', 'paid'])->whereBetween('v.date', [$this->from, $this->to]);
    }

    private function checkDuplicates(): array
    {
        $rows = $this->vouchers()->select('v.id', 'v.number', 'v.date', 'v.net', 'v.payment_request_id')->get();
        $payees = DB::table('payment_request_payees')->whereIn('payment_request_id', $rows->pluck('payment_request_id'))->get()->groupBy('payment_request_id')
            ->map(fn ($g) => strtolower(trim($g->pluck('name')->sort()->join(','))));
        $out = [];
        foreach ($rows->groupBy(fn ($v) => ($payees[$v->payment_request_id] ?? '').'|'.round($v->net, 2)) as $group) {
            $sorted = $group->sortBy('date')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                if (Carbon::parse($sorted[$i - 1]->date)->diffInDays(Carbon::parse($sorted[$i]->date)) <= 7) {
                    foreach ([$sorted[$i - 1], $sorted[$i]] as $v) {
                        $out[$v->id] = $this->row($v->number, 'Paid to '.($payees[$v->payment_request_id] ?? '?'), $v->date, (float) $v->net, "/payments/vouchers/{$v->id}");
                    }
                }
            }
        }

        return array_values($out);
    }

    private function checkNoAttachment(): array
    {
        return $this->vouchers()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_attachments as a')->whereColumn('a.payment_request_id', 'r.id'))
            ->whereNull('r.source_type')->get(['v.id', 'v.number', 'r.title', 'v.date', 'v.net'])
            ->map(fn ($v) => $this->row($v->number, $v->title, $v->date, (float) $v->net, "/payments/vouchers/{$v->id}"))->all();
    }

    private function checkNewBankPaid(): array
    {
        return DB::table('payment_list_items as i')->join('payment_lists as l', 'l.id', '=', 'i.payment_list_id')->join('payment_request_payees as p', 'p.id', '=', 'i.payment_request_payee_id')
            ->join('supplier_bank_accounts as b', fn ($j) => $j->on('b.account_number', '=', 'p.account_number')->where('b.status', 'approved'))
            ->where('l.company_id', $this->company())->where('i.status', 'paid')->whereBetween('l.date', [$this->from, $this->to])
            ->whereNotNull('b.decided_at')->get(['l.id', 'l.number', 'p.name', 'l.date', 'i.amount', 'b.decided_at'])
            ->filter(fn ($r) => ($d = Carbon::parse($r->decided_at)->startOfDay()->diffInDays(Carbon::parse($r->date), false)) >= 0 && $d <= 30)->values()
            ->map(fn ($r) => $this->row($r->number, "{$r->name}: account approved ".Carbon::parse($r->decided_at)->format('d M Y'), $r->date, (float) $r->amount, null))->all();
    }

    private function checkSupplierEmployeeBank(): array
    {
        $employees = DB::table('employee_bank_accounts as e')->join('employees as m', 'm.id', '=', 'e.employee_id')->where('m.company_id', $this->company())
            ->whereNotNull('e.account_number')->get(['e.account_number', 'm.fname', 'm.lname'])->keyBy(fn ($r) => preg_replace('/\s+/', '', (string) $r->account_number));

        return DB::table('supplier_bank_accounts as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->where('b.company_id', $this->company())->get(['s.id', 's.name', 'b.account_number'])
            ->filter(fn ($b) => isset($employees[preg_replace('/\s+/', '', (string) $b->account_number)]))
            ->map(fn ($b) => $this->row($b->name, 'Account '.$b->account_number.' is also the salary account of '.trim($employees[preg_replace('/\s+/', '', (string) $b->account_number)]->fname.' '.$employees[preg_replace('/\s+/', '', (string) $b->account_number)]->lname), null, null, "/procurement/suppliers/{$b->id}"))->values()->all();
    }

    private function checkSingleSource(): array
    {
        $orders = DB::table('purchase_orders')->where('company_id', $this->company())->where('method', 'single')->whereNotIn('status', ['draft', 'rejected', 'cancelled'])
            ->whereBetween('order_date', [$this->from, $this->to])->get(['id', 'number', 'method_reason', 'order_date', 'total'])
            ->map(fn ($o) => $this->row($o->number, 'Single source: '.($o->method_reason ?? ''), $o->order_date, (float) $o->total, "/procurement/orders/{$o->id}"));
        $tenders = DB::table('tenders as t')->join('procurement_methods as m', 'm.id', '=', 't.procurement_method_id')->where('t.company_id', $this->company())->where('m.sourcing', 'single')
            ->whereBetween(DB::raw('DATE(t.created_at)'), [$this->from, $this->to])->get(['t.id', 't.number', 't.title', 't.method_reason', 't.created_at'])
            ->map(fn ($t) => $this->row($t->number, "{$t->title}: ".($t->method_reason ?? ''), $t->created_at, null, "/procurement/tenders/{$t->id}"));

        return $orders->merge($tenders)->all();
    }

    private function checkFewBids(): array
    {
        return DB::table('tenders as t')->join('procurement_methods as m', 'm.id', '=', 't.procurement_method_id')->where('t.company_id', $this->company())->whereNotNull('t.opened_at')
            ->whereBetween(DB::raw('DATE(t.opened_at)'), [$this->from, $this->to])
            ->selectRaw('t.id, t.number, t.title, t.opened_at, m.min_bidders, (SELECT COUNT(*) FROM tender_bids b WHERE b.tender_id = t.id AND b.withdrawn = 0) as bids')->get()
            ->filter(fn ($t) => $t->bids < $t->min_bidders)->map(fn ($t) => $this->row($t->number, "{$t->title}: {$t->bids} bid(s), {$t->min_bidders} required", $t->opened_at, null, "/procurement/tenders/{$t->id}"))->values()->all();
    }

    private function checkAboveEstimate(): array
    {
        return \Modules\Procurement\Models\TenderAward::with(['tender.lines.requisitionLine', 'supplier'])->whereNotNull('letter_sent_at')
            ->whereBetween(DB::raw('DATE(letter_sent_at)'), [$this->from, $this->to])->get()
            ->map(function ($a) {
                $estimate = round($a->tender->lines->where('lot', $a->lot)->sum(fn ($l) => $l->quantity * $l->requisitionLine->estimated_unit_cost), 2);

                return $a->amount > $estimate ? $this->row($a->tender->number, "{$a->lot}: {$a->supplier->name}, estimate ".number_format($estimate, 2), $a->letter_sent_at, $a->amount - $estimate, "/procurement/tenders/{$a->tender_id}") : null;
            })->filter()->values()->all();
    }

    private function checkVariationsNearLimit(): array
    {
        $limit = (float) (\Modules\Procurement\Models\ProcurementSetting::current()->variation_limit_percent ?: 15);

        return \Modules\Procurement\Models\Contract::with('supplier')->where('status', 'active')->get()
            ->map(function ($c) use ($limit) {
                $up = (float) $c->variations()->where('status', 'approved')->where('amount', '>', 0)->sum('amount');

                return $c->value > 0 && $up >= 0.8 * $c->value * $limit / 100 ? $this->row($c->number, "{$c->title} ({$c->supplier?->name}): increases ".round($up / $c->value * 100, 1)."% of {$limit}% allowed", $c->end_date, $up, "/procurement/contracts/{$c->id}") : null;
            })->filter()->values()->all();
    }

    private function checkComplaints(): array
    {
        return DB::table('tender_complaints as c')->join('tenders as t', 't.id', '=', 'c.tender_id')->leftJoin('suppliers as s', 's.id', '=', 'c.supplier_id')->where('t.company_id', $this->company())
            ->whereBetween('c.received_on', [$this->from, $this->to])->get(['t.id', 't.number', 's.name', 'c.outcome', 'c.received_on'])
            ->map(fn ($c) => $this->row($c->number, ($c->name ?? 'Anonymous').': '.$c->outcome, $c->received_on, null, "/procurement/tenders/{$c->id}"))->all();
    }

    private function checkCountLosses(): array
    {
        return DB::table('stock_documents as d')->join('stock_document_lines as l', 'l.stock_document_id', '=', 'd.id')->where('d.company_id', $this->company())->where('d.kind', 'count')
            ->where('d.status', 'posted')->whereBetween('d.date', [$this->from, $this->to])->where('l.value', '<', 0)
            ->groupBy('d.id', 'd.number', 'd.date')->selectRaw('d.id, d.number, d.date, COUNT(*) as items, SUM(l.value) as value')->get()
            ->map(fn ($d) => $this->row($d->number, "{$d->items} item(s) short", $d->date, abs((float) $d->value), "/inventory/documents/{$d->id}"))->all();
    }

    private function checkDisposalLosses(): array
    {
        return DB::table('asset_disposals as d')->join('assets as a', 'a.id', '=', 'd.asset_id')->where('d.company_id', $this->company())->where('d.status', 'approved')->where('d.gain_loss', '<', 0)
            ->whereBetween('d.date', [$this->from, $this->to])->get(['a.id', 'a.number', 'a.name', 'd.method', 'd.date', 'd.gain_loss'])
            ->map(fn ($d) => $this->row($d->number, "{$d->name}: {$d->method}", $d->date, abs((float) $d->gain_loss), "/assets/register/{$d->id}"))->all();
    }

    private function checkLeaverAssets(): array
    {
        $leavers = Employee::where('employment_status', 'terminated')->get(['id', 'fname', 'lname'])->keyBy('id');

        return DB::table('assets')->where('company_id', $this->company())->whereIn('status', ['registered', 'active', 'stored', 'suspended', 'disposal_pending'])
            ->whereIn('employee_id', $leavers->keys())->get(['id', 'number', 'name', 'employee_id', 'cost'])
            ->map(fn ($a) => $this->row($a->number, "{$a->name}: held by ".trim($leavers[$a->employee_id]->fname.' '.$leavers[$a->employee_id]->lname), null, (float) $a->cost, "/assets/register/{$a->id}"))->all();
    }

    private function checkAdmins(): array
    {
        return User::where('company_id', $this->company())->where('is_active', true)->get()
            ->filter(fn ($u) => $u->can('admin.users.manage') || $u->can('admin.roles.manage') || $u->can('admin.roles.assign') || $u->can('admin.workflow.manage'))
            ->map(fn ($u) => $this->row($u->display_name, collect(['admin.users.manage' => 'users', 'admin.roles.manage' => 'roles', 'admin.roles.assign' => 'assign roles', 'admin.workflow.manage' => 'workflows'])
                ->filter(fn ($l, $p) => $u->can($p))->join(', '), $u->last_login_at ?? null, null, null))->values()->all();
    }

    private function checkAccessChanges(): array
    {
        return DB::table('access_changes as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.company_id', $this->company())->where('a.created_at', '>=', now()->subDays(90))
            ->get(['u.username', 'a.type', 'a.reason', 'a.status', 'a.created_at'])
            ->map(fn ($a) => $this->row($a->username, "{$a->type} ({$a->status}): ".($a->reason ?? ''), $a->created_at, null, null))->all();
    }

    private function checkHandovers(): array
    {
        return DB::table('role_handovers as h')->join('roles as r', 'r.id', '=', 'h.role_id')->join('users as f', 'f.id', '=', 'h.from_user_id')->join('users as t', 't.id', '=', 'h.to_user_id')
            ->where('h.company_id', $this->company())->whereNull('h.revoked_at')->where('h.starts_at', '<=', now())->where(fn ($q) => $q->whereNull('h.ends_at')->orWhere('h.ends_at', '>=', now()))
            ->get(['r.name', 'f.username as from', 't.username as to', 'h.starts_at', 'h.reason'])
            ->map(fn ($h) => $this->row($h->name, "{$h->from} → {$h->to}: ".($h->reason ?? ''), $h->starts_at, null, null))->all();
    }

    private function checkLeaverAccounts(): array
    {
        return Employee::with('user')->where('employment_status', 'terminated')->whereHas('user', fn ($u) => $u->where('is_active', true))->get()
            ->map(fn ($e) => $this->row($e->employee_no ?? (string) $e->id, trim($e->fname.' '.$e->lname).' left on '.($e->termination_date?->format('d M Y') ?? '?').'; account '.$e->user->username.' still active', $e->termination_date, null, "/hr/employees/{$e->id}"))->all();
    }

    private function checkOpenYears(): array
    {
        return DB::table('fiscal_years')->where('company_id', $this->company())->where('status', '!=', 'closed')->where('end_date', '<', today())->get(['name', 'end_date', 'status'])
            ->map(fn ($y) => $this->row($y->name, "Ended, still {$y->status}", $y->end_date, null, '/accounting/year-end'))->all();
    }

    private function checkOpeningDifferences(): array
    {
        return collect(app(\Modules\Accounting\Actions\ManageOpeningBalances::class)->reconciliation())->filter(fn ($r) => abs($r['difference']) > 0.009)
            ->map(fn ($r) => $this->row("{$r['code']} {$r['account']}", "{$r['kind_label']}: opening ".number_format($r['opening'], 2).', detail '.number_format($r['approved'], 2), null, $r['difference'], '/accounting/opening-balances'))->values()->all();
    }

    private function checkPendingApprovals(): array
    {
        return DB::table('workflow_instances')->where('company_id', $this->company())->where('status', 'pending')->orderBy('created_at')->get(['id', 'process', 'created_at', 'amount'])
            ->map(fn ($i) => $this->row("{$i->process} #{$i->id}", 'Waiting since '.Carbon::parse($i->created_at)->format('d M Y'), $i->created_at, $i->amount !== null ? (float) $i->amount : null, null))->all();
    }
}
