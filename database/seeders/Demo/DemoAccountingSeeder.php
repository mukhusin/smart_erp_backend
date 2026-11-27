<?php

namespace Database\Seeders\Demo;

use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\FundSource;
use Modules\Accounting\Models\PayrollPostingSetting;
use Modules\Accounting\Models\VatSetting;
use Modules\Accounting\Services\FiscalCalendar;

class DemoAccountingSeeder
{
    public const CHART = [
        ['1000', 'Assets', 'asset', null, false],
        ['1100', 'Cash and bank', 'asset', '1000', false],
        ['1110', 'CRDB main account', 'asset', '1100', true],
        ['1120', 'NMB collections account', 'asset', '1100', true],
        ['1130', 'Petty cash', 'asset', '1100', true],
        ['1140', 'Donor project account (USAID)', 'asset', '1100', true],
        ['1200', 'Receivables', 'asset', '1000', false],
        ['1210', 'Patient debtors', 'asset', '1200', true],
        ['1220', 'Insurance receivables', 'asset', '1200', true],
        ['1230', 'Staff imprest', 'asset', '1200', true],
        ['1240', 'Prepayments', 'asset', '1200', true],
        ['1300', 'Inventory', 'asset', '1000', false],
        ['1310', 'Medical supplies stock', 'asset', '1300', true],
        ['1320', 'Pharmaceuticals stock', 'asset', '1300', true],
        ['1330', 'Stationery and general stock', 'asset', '1300', true],
        ['1340', 'Fuel stock', 'asset', '1300', true],
        ['1400', 'Input VAT', 'asset', '1000', true],
        ['1500', 'Property, plant and equipment', 'asset', '1000', false],
        ['1510', 'Buildings', 'asset', '1500', true],
        ['1520', 'Medical equipment', 'asset', '1500', true],
        ['1530', 'Motor vehicles', 'asset', '1500', true],
        ['1540', 'Furniture and fittings', 'asset', '1500', true],
        ['1550', 'ICT equipment', 'asset', '1500', true],
        ['1590', 'Accumulated depreciation', 'asset', '1500', true],
        ['2000', 'Liabilities', 'liability', null, false],
        ['2100', 'Trade payables', 'liability', '2000', true],
        ['2110', 'Accrued expenses', 'liability', '2000', true],
        ['2150', 'Goods received not invoiced', 'liability', '2000', true],
        ['2200', 'PAYE payable', 'liability', '2000', true],
        ['2210', 'NSSF payable', 'liability', '2000', true],
        ['2220', 'NHIF payable', 'liability', '2000', true],
        ['2230', 'Other staff deductions payable', 'liability', '2000', true],
        ['2300', 'Net salaries payable', 'liability', '2000', true],
        ['2400', 'Output VAT', 'liability', '2000', true],
        ['2500', 'Deferred grant income', 'liability', '2000', true],
        ['3000', 'Equity', 'equity', null, false],
        ['3100', 'Accumulated fund', 'equity', '3000', true],
        ['3200', 'Retained surplus', 'equity', '3000', true],
        ['3300', 'Revaluation surplus', 'equity', '3000', true],
        ['4000', 'Revenue', 'revenue', null, false],
        ['4100', 'Outpatient fees', 'revenue', '4000', true, true],
        ['4110', 'Inpatient fees', 'revenue', '4000', true, true],
        ['4120', 'Laboratory fees', 'revenue', '4000', true, true],
        ['4130', 'Pharmacy sales', 'revenue', '4000', true, true],
        ['4140', 'Theatre fees', 'revenue', '4000', true, true],
        ['4200', 'Insurance claims revenue', 'revenue', '4000', true, true],
        ['4300', 'Grant income', 'revenue', '4000', true, true],
        ['4400', 'Other income', 'revenue', '4000', true, true],
        ['5000', 'Expenses', 'expense', null, false],
        ['5100', 'Medical supplies', 'expense', '5000', true, true],
        ['5110', 'Pharmaceuticals', 'expense', '5000', true, true],
        ['5200', 'Salaries and wages', 'expense', '5000', true, true],
        ['5210', 'Employer statutory costs', 'expense', '5000', true, true],
        ['5220', 'Staff allowances', 'expense', '5000', true, true],
        ['5300', 'Electricity and water', 'expense', '5000', true, true],
        ['5310', 'Fuel and lubricants', 'expense', '5000', true, true],
        ['5320', 'Vehicle maintenance', 'expense', '5000', true, true],
        ['5330', 'Building maintenance', 'expense', '5000', true, true],
        ['5400', 'Stationery and printing', 'expense', '5000', true, true],
        ['5410', 'ICT and software', 'expense', '5000', true, true],
        ['5500', 'Travel and accommodation', 'expense', '5000', true, true],
        ['5510', 'Training and workshops', 'expense', '5000', true, true],
        ['5520', 'Vehicle hire', 'expense', '5000', true, true],
        ['5600', 'Depreciation', 'expense', '5000', true, false],
        ['5610', 'Impairment loss', 'expense', '5000', true, false],
        ['5620', 'Gain or loss on asset disposal', 'expense', '5000', true, false],
        ['5700', 'Bank charges', 'expense', '5000', true, true],
        ['5800', 'Audit and professional fees', 'expense', '5000', true, true],
        ['5900', 'Cleaning and security', 'expense', '5000', true, true],
    ];

    public const EXPENSE_CLASSES = [
        'Nursing' => 'programme', 'Medical' => 'programme', 'Laboratory' => 'programme', 'Pharmacy' => 'programme', 'Planning' => 'programme',
        'Directorate' => 'admin', 'Human Resources' => 'admin', 'Finance' => 'admin', 'Procurement and Supplies' => 'admin', 'Transport' => 'admin',
        'Estates' => 'admin', 'Internal Audit' => 'admin', 'ICT' => 'admin',
    ];

    public function __construct(private readonly Demo $demo) {}

    public function run(): void
    {
        $calendar = app(FiscalCalendar::class);
        $calendar->createYear('2026-07-01');

        $ids = [];
        foreach (self::CHART as $row) {
            [$code, $name, $type, $parent, $postable] = $row;
            $ids[$code] = Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'parent_id' => $parent ? $ids[$parent] : null, 'is_postable' => $postable,
                'is_budgetable' => $row[5] ?? false, 'is_active' => true])->id;
        }
        AccountingSetting::current()->fill(['use_gfs' => false, 'budget_phasing' => 'quarter', 'budget_control' => 'warn', 'retained_earnings_account_id' => $ids['3200'],
            'expense_classes' => true, 'programme_target' => 70])->save();
        foreach (self::EXPENSE_CLASSES as $name => $class) {
            \App\Models\Department::where('name', $name)->whereNull('parent_id')->update(['expense_class' => $class]);
        }

        foreach ([['OWN', 'Own revenue (patient fees)', 'own'], ['USAID', 'USAID HIV care grant', 'donor'], ['GF', 'Global Fund TB programme', 'donor']] as [$c, $n, $k]) {
            FundSource::create(['code' => $c, 'name' => $n, 'kind' => $k, 'is_active' => true]);
        }
        foreach ([['CRDB main account', 'bank', '1110', 'CRDB Bank', '0150123456700'], ['NMB collections', 'bank', '1120', 'NMB Bank', '20110045678'],
            ['Petty cash - HQ', 'cash', '1130', null, null], ['USAID project account', 'bank', '1140', 'CRDB Bank', '0150987654300']] as [$n, $k, $a, $bank, $no]) {
            CashBankAccount::create(['name' => $n, 'kind' => $k, 'account_id' => $ids[$a], 'bank_name' => $bank, 'account_number' => $no, 'currency' => 'TZS', 'is_active' => true]);
        }
        VatSetting::current()->fill(['vat_registered' => true, 'vat_number' => '40-012345-A', 'output_account_id' => $ids['2400'], 'input_account_id' => $ids['1400']])->save();
        PayrollPostingSetting::current()->fill(['enabled' => true, 'salary_account_id' => $ids['5200'], 'employer_account_id' => $ids['5210'], 'net_pay_account_id' => $ids['2300'],
            'paye_account_id' => $ids['2200'], 'nssf_account_id' => $ids['2210'], 'nhif_account_id' => $ids['2220'], 'other_account_id' => $ids['2230']])->save();

        $this->openingBalances($ids);
    }

    private function openingBalances(array $ids): void
    {
        $lines = [
            ['account_id' => $ids['1110'], 'debit' => 850000000], ['account_id' => $ids['1120'], 'debit' => 210000000], ['account_id' => $ids['1130'], 'debit' => 2000000],
            ['account_id' => $ids['1140'], 'debit' => 340000000], ['account_id' => $ids['1510'], 'debit' => 4200000000], ['account_id' => $ids['1520'], 'debit' => 1650000000],
            ['account_id' => $ids['1530'], 'debit' => 780000000], ['account_id' => $ids['1540'], 'debit' => 160000000], ['account_id' => $ids['1550'], 'debit' => 240000000],
            ['account_id' => $ids['1590'], 'credit' => 1450000000], ['account_id' => $ids['2100'], 'credit' => 185000000], ['account_id' => $ids['2500'], 'credit' => 340000000],
            ['account_id' => $ids['3100'], 'credit' => 6457000000],
        ];
        $j = $this->demo->api('POST', 'accounting/journals', ['date' => '2026-07-01', 'description' => 'Opening balances brought forward 2026/27', 'lines' => $lines], 'accountant');
        if ($id = $this->demo->id($j)) {
            $this->demo->api('POST', "accounting/journals/$id/submit", [], 'accountant');
        }
        $this->demo->settle('manual-journal');
    }
}
