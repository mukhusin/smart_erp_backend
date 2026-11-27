<?php

namespace Database\Seeders\Demo;

use App\Models\Department;
use App\Models\Employee;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Budgeting\Models\BudgetItem;

class DemoFinanceSeeder
{
    public function __construct(private readonly Demo $demo) {}

    public function run(): void
    {
        $this->payments();
        $this->imprest();
        $this->pettyCash();
        $this->receivables();
        $this->monthlyCosts();
        $this->adjustments();
    }

    public function adjustments(): void
    {
        $d = $this->demo;
        $acc = Account::pluck('id', 'code');
        $last = now()->subMonth()->endOfMonth();
        $this1 = now()->endOfMonth();
        foreach ([
            [$last, 'accrual', $last->copy()->addDay(), 'Electricity used in '.$last->format('F').', TANESCO bill not yet received', [['5300', 'Estates', 9800000], ['2110', null, -9800000]]],
            [$last, 'prepayment', null, 'Insurance paid for the year: 10 months still prepaid', [['1240', null, 15000000], ['5900', 'Estates', -15000000]]],
            [$this1, 'accrual', $this1->copy()->addDay(), 'External audit fee for '.$this1->format('F').' not yet invoiced', [['5800', 'Finance', 3500000], ['2110', null, -3500000]]],
        ] as [$date, $kind, $reverse, $what, $rows]) {
            $lines = array_map(fn ($r) => ['account_id' => $acc[$r[0]], $r[2] > 0 ? 'debit' : 'credit' => abs($r[2]), 'department_id' => $r[1] ? $this->dept($r[1]) : null, 'description' => $what], $rows);
            $id = $d->id($d->api('POST', 'accounting/journals', ['date' => $date->toDateString(), 'description' => $what, 'adjustment' => $kind,
                'reverse_on' => $reverse?->toDateString(), 'lines' => $lines], 'accountant'));
            $id && $d->api('POST', "accounting/journals/$id/submit", [], 'accountant');
        }
        $d->settle('manual-journal');
    }

    private const MONTHLY_COSTS = [
        ['Nursing', '5100', 12600000, 'Ward consumables used'], ['Pharmacy', '5110', 19600000, 'Medicines dispensed'], ['Laboratory', '5100', 6300000, 'Reagents and lab consumables used'],
        ['Medical', '5100', 4900000, 'Theatre consumables used'], ['Estates', '5300', 10150000, 'Electricity and water'], ['Estates', '5330', 2800000, 'Building and generator maintenance'],
        ['Estates', '5900', 4550000, 'Cleaning and security services'], ['Transport', '5310', 5250000, 'Fuel'], ['Transport', '5320', 1750000, 'Vehicle repairs'],
        ['ICT', '5410', 2450000, 'Internet and software subscriptions'], ['Finance', '5700', 420000, 'Bank charges'], ['Human Resources', '5510', 1400000, 'Staff training'],
        ['Directorate', '5500', 1750000, 'Travel and meetings'], ['Planning', '5500', 700000, 'Monitoring visits'], ['Procurement and Supplies', '5400', 840000, 'Stationery and printing'],
    ];

    private function monthlyCosts(): void
    {
        $d = $this->demo;
        $acc = Account::pluck('id', 'code');
        $credit = ['5100' => '1310', '5110' => '1320'];
        foreach ([3, 2, 1] as $back) {
            $month = now()->subMonths($back)->endOfMonth();
            $lines = [];
            $totals = [];
            foreach (self::MONTHLY_COSTS as $i => [$dept, $code, $amount, $what]) {
                $amount = round($amount * (1 + (($i + $back) % 5 - 2) * 0.04), -3);
                $lines[] = ['account_id' => $acc[$code], 'debit' => $amount, 'department_id' => $this->dept($dept), 'description' => $what];
                $totals[$credit[$code] ?? '1110'] = ($totals[$credit[$code] ?? '1110'] ?? 0) + $amount;
            }
            foreach ($totals as $code => $sum) {
                $lines[] = ['account_id' => $acc[$code], 'credit' => $sum, 'description' => $code === '1110' ? 'Paid from CRDB main account' : 'Issued from stores'];
            }
            $id = $d->id($d->api('POST', 'accounting/journals', ['date' => $month->toDateString(), 'description' => 'Operating costs '.$month->format('F Y'), 'lines' => $lines], 'accountant'));
            $id && $d->api('POST', "accounting/journals/$id/submit", [], 'accountant');
        }
        $d->settle('manual-journal');
    }

    private function dept(string $name): int
    {
        return (int) Department::where('name', $name)->whereNull('parent_id')->value('id');
    }

    private function item(string $dept, ?string $code = null): ?int
    {
        $acc = $code ? Account::where('code', $code)->value('id') : null;

        return BudgetItem::where('department_id', $this->dept($dept))->when($acc, fn ($q) => $q->where('account_id', $acc))->orderBy('id')->value('id')
            ?? BudgetItem::where('department_id', $this->dept($dept))->orderBy('id')->value('id');
    }

    private function payments(): void
    {
        $d = $this->demo;
        $bank = CashBankAccount::where('name', 'CRDB main account')->value('id');
        $requests = [
            ['Finance', 'finmanager', 'External audit fee - first instalment', '5800', [['Ernst & Young Tanzania', 'bank', 'Stanbic Bank', '9100045678', 14000000]], 'paid'],
            ['Estates', 'assets', 'TANESCO electricity bill October', '5300', [['TANESCO', 'bank', 'CRDB Bank', '0150300100', 13850000]], 'paid'],
            ['Estates', 'assets', 'DAWASA water bill October', '5300', [['DAWASA', 'bank', 'NMB Bank', '2011099887', 2650000]], 'listed'],
            ['ICT', 'ict', 'HMIS annual licence renewal', '5410', [['Afya Systems Ltd', 'bank', 'NBC Bank', '0110233445', 42000000]], 'approved'],
            ['Human Resources', 'hrmanager', 'Facilitator fees - nursing CPD workshop', '5510', [['Dr. Anna Mchome', 'mobile', null, '0754112233', 1800000]], 'approved'],
            ['Directorate', 'dg', 'Board meeting sitting allowances', '5800', [['Board members (8)', 'bank', 'CRDB Bank', '0150777001', 3200000]], 'pending'],
            ['Medical', 'medical', 'Specialist outreach - Kibaha', '5500', [['Kibaha Lodge', 'bank', 'NMB Bank', '2011456123', 1250000], ['Driver allowances', 'mobile', null, '0713998877', 350000]], 'pending'],
            ['Nursing', 'nursing', 'Hand hygiene audit materials', '5100', [['Afya Medical Distributors', 'bank', 'Equity Bank', '3000123456', 780000]], 'draft'],
        ];
        $vouchers = [];
        foreach ($requests as $n => [$dept, $as, $title, $code, $payees, $stage]) {
            $total = array_sum(array_column($payees, 4));
            $id = $d->id($d->api('POST', 'payments/requests', ['department_id' => $this->dept($dept), 'title' => $title, 'description' => $title, 'needed_by' => now()->addDays(7 + $n)->toDateString(),
                'lines' => [['budget_item_id' => $this->item($dept, $code), 'amount' => $total, 'description' => $title]],
                'payees' => array_map(fn ($p) => ['name' => $p[0], 'method' => $p[1], 'bank_name' => $p[2], $p[1] === 'mobile' ? 'mobile_number' : 'account_number' => $p[3], 'amount' => $p[4]], $payees)], $as));
            if (! $id || $stage === 'draft') {
                continue;
            }
            $d->api('POST', "payments/requests/$id/submit", [], $as);
            if ($stage === 'pending') {
                continue;
            }
            $d->settle('payment-request', fn ($i) => $i->subject_id !== $id);
            if ($stage === 'approved') {
                continue;
            }
            $v = $d->id($d->api('POST', "payments/requests/$id/voucher", ['cash_bank_account_id' => $bank, 'date' => now()->subDays(6 - $n)->toDateString()], 'accountant'));
            $d->settle('payment-voucher');
            if ($v) {
                $vouchers[] = $v;
            }
        }
        if ($vouchers) {
            $list = $d->id($d->api('POST', 'payments/lists', ['cash_bank_account_id' => $bank, 'method' => 'bank', 'date' => now()->subDays(2)->toDateString(), 'voucher_ids' => $vouchers], 'cashier'));
            $list && $d->settle('payment-list');
        }
    }

    private function imprest(): void
    {
        $d = $this->demo;
        $d->api('PUT', 'payments/settings', ['payable_account_id' => Account::where('code', '2100')->value('id'), 'imprest_account_id' => Account::where('code', '1230')->value('id'),
            'imprest_retire_days' => 14, 'imprest_one_open' => true, 'withholding_account_id' => null, 'require_attachment' => false], 'finmanager');
        $bank = CashBankAccount::where('name', 'CRDB main account')->value('id');
        foreach ([['Medical', 'doctor', 'Mobile clinic - Bagamoyo', 950000, 'paid'], ['ICT', 'ict', 'Network installation at Arusha branch', 1600000, 'paid'], ['Planning', 'planning', 'Quarterly review field visits', 720000, 'pending']] as $n => [$dept, $as, $title, $amount, $stage]) {
            $holder = $d->user($as)->employee;
            $id = $d->id($d->api('POST', 'payments/requests', ['kind' => 'imprest', 'holder_employee_id' => $holder->id, 'department_id' => $this->dept($dept), 'title' => $title,
                'needed_by' => now()->subDays(12 - $n * 5)->toDateString(), 'retire_by' => now()->subDays(12 - $n * 5)->addDays(13)->toDateString(), 'lines' => [['budget_item_id' => $this->item($dept, '5500'), 'amount' => $amount]],
                'payees' => [['method' => 'mobile', 'mobile_number' => $holder->phone_number]]], $as));
            if (! $id) {
                continue;
            }
            $d->api('POST', "payments/requests/$id/submit", [], $as);
            if ($stage === 'pending') {
                continue;
            }
            $d->settle('payment-request', fn ($i) => $i->subject_id !== $id);
            $d->api('POST', "payments/requests/$id/voucher", ['cash_bank_account_id' => $bank, 'date' => now()->subDays(15 - $n)->toDateString()], 'accountant');
            $d->settle('payment-voucher');
            if ($stage === 'retired') {
                $ret = $d->id($d->api('POST', "payments/requests/$id/retirements", ['date' => now()->subDays(3)->toDateString(),
                    'lines' => [['budget_item_id' => $this->item($dept, '5500'), 'description' => 'Fuel, accommodation and allowances', 'amount' => $amount - 85000]]], $as));
                $ret && $d->api('POST', "payments/retirements/$ret/submit", [], $as);
                $d->settle('imprest-retirement');
            }
        }
    }

    private function pettyCash(): void
    {
        $d = $this->demo;
        $cash = CashBankAccount::where('name', 'Petty cash - HQ')->value('id');
        $bank = CashBankAccount::where('name', 'CRDB main account')->value('id');
        $float = $d->id($d->api('POST', 'payments/petty-cash', ['cash_bank_account_id' => $cash, 'custodian_id' => $d->user('cashier')->id, 'float_amount' => 500000, 'max_voucher' => 100000, 'replenish_from_id' => $bank], 'finmanager'));
    }

    private function receivables(): void
    {
        $d = $this->demo;
        $acc = Account::pluck('id', 'code');
        $bank = CashBankAccount::where('name', 'NMB collections')->value('id');
        $d->api('PUT', 'receivables/settings', ['receivable_account_id' => $acc['1220'], 'default_terms_days' => 30, 'credit_check' => 'warn'], 'finmanager');
        $customers = [];
        foreach ([['NHIF', 'insurer', 45, 500000000], ['Jubilee Insurance', 'insurer', 30, 150000000], ['AAR Insurance', 'insurer', 30, 120000000], ['Strategis Insurance', 'insurer', 30, 80000000],
            ['Tanzania Breweries (staff scheme)', 'company', 30, 60000000], ['Vodacom Tanzania (corporate)', 'company', 30, 60000000], ['Juma Ali Mohamed', 'patient', 14, null]] as [$name, $kind, $terms, $limit]) {
            $customers[$name] = $d->id($d->api('POST', 'receivables/customers', ['name' => $name, 'kind' => $kind, 'terms_days' => $terms, 'credit_limit' => $limit], 'billing'));
        }
        $lines = fn (float $f) => [
            ['description' => 'Outpatient consultations', 'account_id' => $acc['4100'], 'department_id' => $this->dept('Medical'), 'quantity' => round(120 * $f), 'unit_price' => 30000],
            ['description' => 'Laboratory investigations', 'account_id' => $acc['4120'], 'department_id' => $this->dept('Laboratory'), 'quantity' => round(260 * $f), 'unit_price' => 15000],
            ['description' => 'Pharmacy - dispensed medicines', 'account_id' => $acc['4130'], 'department_id' => $this->dept('Pharmacy'), 'quantity' => 1, 'unit_price' => round(9500000 * $f)],
            ['description' => 'Inpatient bed days', 'account_id' => $acc['4110'], 'department_id' => $this->dept('Nursing'), 'quantity' => round(85 * $f), 'unit_price' => 80000],
            ['description' => 'Theatre procedures', 'account_id' => $acc['4140'], 'department_id' => $this->dept('Medical'), 'quantity' => round(14 * $f), 'unit_price' => 650000],
        ];
        $plan = [['NHIF', 3.5], ['Jubilee Insurance', 1.2], ['AAR Insurance', 0.95], ['Strategis Insurance', 0.6], ['Tanzania Breweries (staff scheme)', 0.45], ['Vodacom Tanzania (corporate)', 0.35]];
        foreach ([3, 2, 1] as $back) {
            $month = now()->subMonths($back)->endOfMonth();
            foreach ($plan as $n => [$name, $f]) {
                $inv = $d->id($d->api('POST', 'receivables/invoices', ['customer_id' => $customers[$name], 'date' => $month->toDateString(), 'reference' => 'Claim '.$month->format('M Y'), 'lines' => $lines($f)], 'billing'));
                if (! $inv) {
                    continue;
                }
                $d->api('POST', "receivables/invoices/$inv/issue", [], 'billing');
                $total = (float) ($d->api('GET', "receivables/invoices/$inv", [], 'billing')['data']['total'] ?? 0);
                $paid = $back === 3 ? $total : ($back === 2 && $n % 2 === 0 ? round($total * 0.6) : 0);
                if ($paid > 0) {
                    $d->api('POST', 'receivables/receipts', ['kind' => 'invoice', 'customer_id' => $customers[$name], 'date' => $month->copy()->addDays(25)->min(now())->toDateString(), 'cash_bank_account_id' => $bank,
                        'method' => 'bank', 'reference' => 'TT-'.$month->format('ym').$n, 'allocations' => [['invoice_id' => $inv, 'amount' => $paid]]], 'cashier');
                }
            }
        }
        for ($i = 0; $i < 12; $i++) {
            $d->api('POST', 'receivables/receipts', ['kind' => 'direct', 'payer_name' => ['Cash patients - OPD', 'Cash patients - Pharmacy', 'Cash patients - Laboratory'][$i % 3], 'date' => now()->subDays(1 + $i * 2)->toDateString(),
                'cash_bank_account_id' => $bank, 'method' => $i % 2 ? 'mobile' : 'cash', 'reference' => 'DAY-'.now()->subDays(1 + $i * 2)->format('md'),
                'lines' => [['description' => 'Daily cash collections', 'account_id' => $acc[['4100', '4130', '4120'][$i % 3]], 'amount' => 3800000 + $i * 260000]]], 'cashier');
        }
    }
}
