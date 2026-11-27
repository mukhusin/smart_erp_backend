<?php

namespace Database\Seeders\Demo;

use App\Models\Department;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\FundSource;

class DemoPlanningSeeder
{
    public const OBJECTIVES = [
        ['SO1', 'Improve quality and safety of patient care'],
        ['SO2', 'Increase access to specialised services'],
        ['SO3', 'Strengthen financial sustainability'],
        ['SO4', 'Attract, develop and retain skilled staff'],
        ['SO5', 'Modernise infrastructure, equipment and ICT'],
    ];

    public const ACTIVITIES = [
        'Directorate' => [['SO3', 'Quarterly board and management meetings', 'Board resolutions', '4 meetings', '5800', 4, 3500000], ['SO1', 'Patient satisfaction survey', 'Survey report', '2 surveys', '5800', 2, 4500000], ['SO2', 'Partnership visits with referral hospitals', 'MoUs signed', '3 MoUs', '5500', 3, 2800000]],
        'Human Resources' => [['SO4', 'Staff continuous professional development', 'Staff trained', '120 staff', '5510', 120, 250000], ['SO4', 'Recruit specialists and nurses', 'Posts filled', '15 posts', '5800', 15, 600000], ['SO4', 'Staff wellness week', 'Wellness week held', '1 event', '5220', 1, 6000000]],
        'Finance' => [['SO3', 'Annual external audit', 'Audited financial statements', '1 audit', '5800', 1, 28000000], ['SO3', 'Insurance claims follow-up', 'Rejection rate below 5%', '12 reviews', '5500', 12, 450000], ['SO3', 'Revenue collection system upgrade', 'Cashless collections', '100% cashless', '5410', 1, 18000000]],
        'Planning' => [['SO3', 'Annual plan and budget preparation', 'Approved plan', '1 plan', '5510', 1, 9000000], ['SO1', 'Quarterly performance reviews', 'Review reports', '4 reviews', '5500', 4, 2200000]],
        'Procurement and Supplies' => [['SO3', 'Supplier pre-qualification', 'Approved supplier list', '1 list', '5400', 1, 3500000], ['SO3', 'Stock taking', 'Stock count reports', '2 counts', '5400', 2, 2500000]],
        'Transport' => [['SO2', 'Ambulance and vehicle servicing', 'Vehicles serviced', '48 services', '5320', 48, 650000], ['SO2', 'Fuel for patient referrals and outreach', 'Litres used', '24,000 litres', '5310', 24000, 3100]],
        'Estates' => [['SO5', 'Renovate maternity ward', 'Ward renovated', '1 ward', '5330', 1, 85000000], ['SO5', 'Preventive maintenance of generators', 'Generators serviced', '12 services', '5330', 12, 1200000], ['SO5', 'Electricity and water bills', 'Uninterrupted utilities', '12 months', '5300', 12, 14500000]],
        'Internal Audit' => [['SO3', 'Risk-based internal audits', 'Audit reports', '8 audits', '5500', 8, 900000], ['SO3', 'Audit committee meetings', 'Minutes', '4 meetings', '5800', 4, 1800000]],
        'ICT' => [['SO5', 'Hospital information system licences', 'Licences renewed', '1 renewal', '5410', 1, 42000000], ['SO5', 'Replace old computers', 'Computers replaced', '30 computers', '5410', 30, 1650000]],
        'Nursing' => [['SO1', 'Hand hygiene audits', 'Monthly audit reports', '12 audits', '5100', 12, 850000], ['SO1', 'Infection prevention supplies', 'Wards stocked', '12 months', '5100', 12, 6500000], ['SO4', 'Nursing skills drills', 'Nurses trained', '60 nurses', '5510', 60, 120000]],
        'Medical' => [['SO2', 'Specialist outreach clinics', 'Clinics held', '12 clinics', '5500', 12, 2400000], ['SO1', 'Clinical audit and mortality reviews', 'Review reports', '12 reviews', '5800', 12, 300000], ['SO2', 'Theatre consumables', 'Operations done', '1,800 operations', '5100', 1800, 45000]],
        'Laboratory' => [['SO1', 'Laboratory reagents', 'Tests done', '60,000 tests', '5100', 12, 9500000], ['SO1', 'External quality assessment', 'EQA rounds passed', '4 rounds', '5800', 4, 1500000]],
        'Pharmacy' => [['SO1', 'Essential medicines', 'Availability above 95%', '12 months', '5110', 12, 38000000], ['SO1', 'Pharmacovigilance training', 'Staff trained', '25 staff', '5510', 25, 180000]],
    ];

    public function __construct(private readonly Demo $demo) {}

    public static function heads(): array
    {
        return collect(DemoHrSeeder::PEOPLE)->filter(fn ($p) => ($p[8] ?? null) === 'head' && $p[0] !== 'auditor')->mapWithKeys(fn ($p) => [$p[4] => $p[0]])->all();
    }

    public function run(): void
    {
        $d = $this->demo;
        $sp = $d->id($d->api('POST', 'planning/strategic-plans', ['title' => 'Strategic plan 2025–2030', 'start_year' => 2025, 'end_year' => 2030,
            'vision' => 'To be the leading private referral hospital in East Africa.', 'mission' => 'To provide affordable, high-quality and compassionate health care.'], 'planning'));
        if (! $sp) {
            return;
        }
        $d->api('PUT', "planning/strategic-plans/$sp", ['title' => 'Strategic plan 2025–2030', 'start_year' => 2025, 'end_year' => 2030, 'status' => 'active'], 'planning');
        $objectives = [];
        foreach (self::OBJECTIVES as $i => [$code, $title]) {
            $objectives[$code] = $d->id($d->api('POST', "planning/strategic-plans/$sp/objectives", ['code' => $code, 'title' => $title, 'sort_order' => $i], 'planning'));
        }
        $plan = $d->id($d->api('POST', 'planning/annual-plans', ['title' => 'Annual plan 2026/27', 'year' => 2026, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30',
            'submission_due' => '2026-06-15', 'strategic_plan_id' => $sp], 'planning'));
        $d->api('POST', "planning/annual-plans/$plan/open", [], 'planning');

        $activities = [];
        foreach (self::heads() as $dept => $head) {
            $dep = Department::where('name', $dept)->whereNull('parent_id')->first();
            $base = "planning/annual-plans/$plan/departments/{$dep->id}";
            foreach (self::ACTIVITIES[$dept] ?? [] as $i => [$so, $title, $output, $target, $account, $qty, $cost]) {
                $id = $d->id($d->api('POST', "$base/activities", ['code' => ($i + 1).'.'.($i + 1), 'title' => $title, 'output' => $output, 'target' => $target,
                    'quarters' => '1,2,3,4', 'strategic_objective_id' => $objectives[$so]], $head));
                $activities[$dept][] = [$id, $account, $qty, $cost, $title];
            }
            if (! in_array($dept, ['Planning', 'Estates'], true)) {
                $d->api('POST', "$base/submit", [], $head);
            }
        }
        $d->settle('department-plan', keepLatest: 2);

        $this->budget($activities);
    }

    private function budget(array $activities): void
    {
        $d = $this->demo;
        $year = FiscalYear::where('name', '2026/27')->value('id');
        $annual = \Modules\Planning\Models\AnnualPlan::value('id');
        $budget = $d->id($d->api('POST', 'budgeting/budgets', ['fiscal_year_id' => $year, 'title' => 'Budget 2026/27', 'annual_plan_id' => $annual], 'budget'));
        if (! $budget) {
            return;
        }
        $d->api('POST', "budgeting/budgets/$budget/open", [], 'budget');
        $acc = Account::pluck('id', 'code');
        $own = FundSource::where('code', 'OWN')->value('id');
        $usaid = FundSource::where('code', 'USAID')->value('id');
        foreach (self::heads() as $dept => $head) {
            $dep = Department::where('name', $dept)->whereNull('parent_id')->first();
            $base = "budgeting/budgets/$budget/departments/{$dep->id}";
            foreach ($activities[$dept] ?? [] as $i => [$activity, $account, $qty, $cost, $title]) {
                $d->api('POST', "$base/items", ['task_activity_id' => $activity, 'account_id' => $acc[$account], 'fund_source_id' => $dept === 'Laboratory' && $i === 0 ? $usaid : $own,
                    'description' => $title, 'quantity' => $qty, 'unit_cost' => $cost], $head);
            }
            $d->api('POST', "$base/items", ['account_id' => $acc['5400'], 'fund_source_id' => $own, 'description' => 'Stationery and office supplies', 'quantity' => 12, 'unit' => 'months', 'unit_cost' => 450000], $head);
            $d->api('POST', "$base/submit", [], $head);
        }
        $d->settle('department-budget');
        $d->api('POST', "budgeting/budgets/$budget/submit", [], 'budget');
        $d->settle('budget-approval');
        foreach ([1, 2] as $q) {
            $d->api('POST', "budgeting/budgets/$budget/releases", ['period' => $q], 'budget', true);
        }
        $d->settle('budget-release');
        $items = \Modules\Budgeting\Models\BudgetItem::where('budget_id', $budget)->where('department_id', Department::where('name', 'Nursing')->whereNull('parent_id')->value('id'))->orderBy('id')->pluck('id');
        if ($items->count() >= 2) {
            $d->api('POST', "budgeting/budgets/$budget/transfers", ['kind' => 'transfer', 'from_item_id' => $items[2] ?? $items[0], 'to_item_id' => $items[1], 'amount' => 2500000,
                'reason' => 'More infection prevention supplies needed for the cholera season'], 'nursing');
        }
    }
}
