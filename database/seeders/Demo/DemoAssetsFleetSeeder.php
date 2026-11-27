<?php

namespace Database\Seeders\Demo;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
use Modules\Accounting\Models\Account;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Inventory\Models\ItemCategory;
use Modules\Procurement\Models\Supplier;

class DemoAssetsFleetSeeder
{
    private const ASSETS = [
        ['VEH', 'Toyota Land Cruiser ambulance', 'Nursing', 'opening', '2021-03-15', 165000000, 1, 'vehicle', ['registration' => 'T 512 DKL']],
        ['VEH', 'Toyota Hiace ambulance', 'Nursing', 'opening', '2022-06-01', 98000000, 1, 'vehicle', ['registration' => 'T 744 DPR']],
        ['VEH', 'Toyota Land Cruiser station wagon', 'Directorate', 'opening', '2020-01-10', 185000000, 1, 'vehicle', ['registration' => 'T 109 DGB']],
        ['VEH', 'Toyota Hilux double cabin', 'Transport', 'opening', '2023-02-20', 92000000, 1, 'vehicle', ['registration' => 'T 233 DXA']],
        ['VEH', 'Isuzu NPR truck', 'Procurement and Supplies', 'opening', '2019-08-05', 120000000, 1, 'vehicle', ['registration' => 'T 881 DCE']],
        ['VEH', 'Toyota Coaster staff bus', 'Transport', 'donation', '2026-08-12', 210000000, 1, 'vehicle', ['registration' => 'T 340 EAB']],
        ['MEQ', 'Digital X-ray machine (Siemens)', 'Medical', 'opening', '2022-09-01', 420000000, 1, 'medical', []],
        ['MEQ', 'Ultrasound machine (GE Logiq)', 'Medical', 'opening', '2023-04-18', 95000000, 2, 'medical', []],
        ['MEQ', 'Anaesthesia machine', 'Medical', 'opening', '2021-11-02', 78000000, 3, 'medical', []],
        ['MEQ', 'Patient monitor (multi-parameter)', 'Nursing', 'opening', '2023-01-15', 6500000, 12, 'medical', []],
        ['MEQ', 'Haematology analyser (Sysmex)', 'Laboratory', 'opening', '2022-03-10', 68000000, 1, 'medical', []],
        ['MEQ', 'Biochemistry analyser', 'Laboratory', 'donation', '2026-09-01', 85000000, 1, 'medical', []],
        ['MEQ', 'Oxygen concentrator', 'Nursing', 'opening', '2021-05-20', 3200000, 15, 'medical', []],
        ['FUR', 'Electric hospital bed', 'Nursing', 'opening', '2020-10-01', 2800000, 40, 'furniture', []],
        ['FUR', 'Office desk and chair set', 'Finance', 'opening', '2022-01-12', 950000, 12, 'furniture', []],
        ['ICT', 'Dell Latitude laptop', 'ICT', 'opening', '2024-02-01', 2400000, 25, 'computer', []],
        ['ICT', 'HP desktop computer', 'Finance', 'opening', '2023-03-01', 1650000, 30, 'computer', []],
        ['ICT', 'Network server (Dell PowerEdge)', 'ICT', 'opening', '2022-08-15', 38000000, 2, 'computer', []],
        ['BLD', 'Maternity wing building', 'Nursing', 'opening', '2015-07-01', 1800000000, 1, 'building', []],
        ['BLD', 'Outpatient block', 'Medical', 'opening', '2012-07-01', 2400000000, 1, 'building', []],
    ];

    public function __construct(private readonly Demo $demo) {}

    public function run(): void
    {
        $this->assets();
        $this->fleet();
    }

    private function dept(string $name): int
    {
        return (int) Department::where('name', $name)->whereNull('parent_id')->value('id');
    }

    private function assets(): void
    {
        $d = $this->demo;
        $acc = Account::pluck('id', 'code');
        $d->api('PUT', 'assets/settings', ['number_pattern' => '{CAT}-{YEAR}-{SEQ}', 'depreciation_start' => 'in_use', 'opening_account_id' => $acc['3100'], 'donation_account_id' => $acc['4300'],
            'gain_loss_account_id' => $acc['5620'], 'revaluation_account_id' => $acc['3300'], 'impairment_account_id' => $acc['5610'], 'confirmation_month' => 1], 'assets');
        $cats = ItemCategory::where('type', 'asset')->pluck('id', 'code');
        $laptops = [];
        foreach (self::ASSETS as [$cat, $name, $dept, $type, $date, $cost, $qty, $class, $details]) {
            $age = max(0, \Illuminate\Support\Carbon::parse($date)->diffInMonths('2026-06-30'));
            $life = ['VEH' => 60, 'MEQ' => 96, 'FUR' => 96, 'ICT' => 48, 'BLD' => 480][$cat];
            $opening = $type === 'opening' ? round($cost * min(0.85, $age / $life) * ($cat === 'BLD' ? 1 : 0.9), -3) : 0;
            $rows = $d->api('POST', 'assets/register', ['item_category_id' => $cats[$cat], 'name' => $name, 'acquisition_type' => $type, 'acquisition_date' => $date, 'in_use_date' => $date,
                'cost' => $cost, 'opening_depreciation' => $opening ?: null, 'asset_class' => $class, 'details' => $details ?: null, 'department_id' => $this->dept($dept), 'quantity' => $qty,
                'serials' => $qty > 1 ? array_map(fn ($i) => strtoupper(substr($cat, 0, 2)).'-'.substr(md5($name), 0, 4).'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), range(1, $qty)) : null], 'assets');
            if ($cat === 'ICT' && str_contains($name, 'laptop')) {
                $laptops = array_column($rows['data'] ?? [], 'id');
            }
        }
        $d->api('POST', 'assets/register/submit-drafts', [], 'assets');
        $d->settle('asset-register');
        $people = ['dg', 'dfinance', 'dhr', 'dplanning', 'hrmanager', 'finmanager', 'procmanager', 'planning', 'auditor', 'ict', 'accountant', 'budget', 'transport', 'assets'];
        foreach (array_slice($laptops, 0, count($people)) as $i => $id) {
            $e = $d->user($people[$i])->employee;
            $d->api('POST', "assets/register/$id/assign", ['employee_id' => $e->id, 'department_id' => $e->department_id, 'date' => '2024-02-05', 'condition' => 'good', 'reason' => 'Work laptop'], 'assets');
        }
        foreach (['2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31'] as $end) {
            $d->api('POST', 'assets/depreciation/run', ['period_end' => $end], 'assets');
        }
    }

    private function fleet(): void
    {
        $d = $this->demo;
        $types = [];
        foreach ([['Ambulance', ['C', 'D'], 90], ['Station wagon', ['B', 'C'], 93], ['Pick-up', ['B', 'C'], 80], ['Truck', ['C', 'E'], 140], ['Bus', ['D', 'E'], 120]] as [$n, $classes, $tank]) {
            $types[$n] = $d->id($d->api('POST', 'fleet/types', ['name' => $n, 'kind' => 'vehicle', 'licence_classes' => $classes, 'tank_capacity' => $tank], 'transport'));
        }
        $units = [];
        foreach ([['T 512 DKL', 'Toyota Land Cruiser ambulance', 'Ambulance', 148200], ['T 744 DPR', 'Toyota Hiace ambulance', 'Ambulance', 96400], ['T 109 DGB', 'Toyota Land Cruiser station wagon', 'Station wagon', 187650],
            ['T 233 DXA', 'Toyota Hilux double cabin', 'Pick-up', 54300], ['T 881 DCE', 'Isuzu NPR truck', 'Truck', 212400], ['T 340 EAB', 'Toyota Coaster staff bus', 'Bus', 38900]] as [$reg, $name, $type, $km]) {
            $units[$reg] = $d->id($d->api('POST', 'fleet/units', ['kind' => 'vehicle', 'vehicle_type_id' => $types[$type], 'registration' => $reg, 'name' => $name, 'ownership' => 'owned', 'department_id' => $this->dept($type === 'Station wagon' ? 'Directorate' : 'Transport'),
                'reading' => $km, 'reading_date' => now()->subDays(40)->toDateString()], 'transport'));
        }
        $gen = $d->id($d->api('POST', 'fleet/units', ['kind' => 'generator', 'name' => 'Main theatre generator 250 kVA', 'ownership' => 'owned', 'reading' => 8640, 'department_id' => $this->dept('Estates')], 'transport'));

        $drivers = [];
        $driverPos = JobPosition::where('title', 'Driver')->value('id');
        foreach (Employee::where('job_position_id', $driverPos)->orderBy('id')->get() as $i => $e) {
            $id = $d->id($d->api('POST', 'fleet/drivers', ['employee_id' => $e->id], 'transport'));
            if ($id) {
                $d->api('POST', "fleet/drivers/$id/licences", ['number' => 'DL-'.(4000100 + $i * 37), 'classes' => $i % 2 ? ['B', 'C', 'D'] : ['B', 'C', 'D', 'E'],
                    'issued_on' => now()->subYears(3)->toDateString(), 'expires_on' => now()->addMonths($i === 0 ? 1 : 24)->toDateString()], 'transport');
                $drivers[] = [$id, $e];
            }
        }
        $regs = array_keys($units);
        foreach (array_slice($drivers, 0, count($regs)) as $i => [$id]) {
            $d->api('POST', "fleet/units/{$units[$regs[$i]]}/assign", ['fleet_driver_id' => $id, 'starts_on' => now()->subMonths(2)->toDateString()], 'transport');
        }
        foreach ($types as $n => $type) {
            $d->api('POST', 'fleet/service-plans', ['name' => 'Minor service', 'vehicle_type_id' => $type, 'every_reading' => 5000, 'every_months' => 6, 'warn_reading' => 500, 'tasks' => 'Engine oil, filters and general check'], 'transport');
        }

        $this->transport($units, $drivers);
        $this->fuel($units);
        $this->maintenance($units);
    }

    private function transport(array $units, array $drivers): void
    {
        $d = $this->demo;
        $trips = [['doctor', 'Specialist outreach clinic', 'Bagamoyo District Hospital', 2, 4, 'T 109 DGB'], ['nursing', 'Community immunisation outreach', 'Kibaha', 3, 6, 'T 233 DXA'],
            ['procmanager', 'Collect supplies from MSD', 'MSD Keko', 1, 2, 'T 881 DCE'], ['hrmanager', 'Staff team building', 'Bagamoyo', 6, 25, 'T 340 EAB'],
            ['ict', 'Network installation at Arusha branch', 'Arusha', 5, 2, 'T 109 DGB'], ['lab', 'Deliver samples to NHL-QATC', 'Mabibo', 4, 2, 'T 233 DXA'],
            ['medical', 'Referral transfer to Muhimbili', 'Muhimbili National Hospital', 1, 3, 'T 744 DPR'], ['planning', 'Quarterly review meeting', 'Morogoro', 8, 4, 'T 109 DGB']];
        foreach ($trips as $n => [$as, $purpose, $dest, $days, $pax, $reg]) {
            $start = now()->addDays($days)->setTime(8, 0);
            $body = ['purpose' => $purpose, 'destination' => $dest, 'departs_at' => $start->format('Y-m-d H:i'), 'returns_at' => $start->copy()->setTime(17, 0)->format('Y-m-d H:i'), 'passengers' => $pax];
            $id = $d->id($d->api('POST', 'fleet/requests', $body, $as));
            if (! $id || $n === 7) {
                continue;
            }
            $d->settle('transport-request', fn ($i) => $i->subject_id !== $id);
            if ($n >= 5) {
                continue;
            }
            $driver = $drivers[$n % max(1, count($drivers))][0] ?? null;
            $d->api('POST', 'fleet/trips', ['transport_request_id' => $id, 'fleet_unit_id' => $units[$reg], 'fleet_driver_id' => $driver, 'override_reason' => 'Planned in transport meeting'] + $body, 'transport');
        }
    }

    private function fuel(array $units): void
    {
        $d = $this->demo;
        $puma = Supplier::where('name', 'Pwani Motors Ltd')->value('id');
        $dept = $this->dept('Transport');
        $item = BudgetItem::where('department_id', $dept)->where('account_id', Account::where('code', '5310')->value('id'))->value('id');
        $tank = $d->id($d->api('POST', 'fleet/fuel-stores', ['name' => 'Hospital diesel tank', 'kind' => 'own', 'fuel_type' => 'diesel', 'capacity' => 10000, 'reorder_level' => 1500], 'transport'));
        $d->api('POST', 'fleet/fuel-stores', ['name' => 'Puma Mwenge', 'kind' => 'station', 'fuel_type' => 'diesel', 'supplier_id' => $puma, 'price' => 3150], 'transport');
        if (! $tank) {
            return;
        }
        $rc = $d->api('POST', "fleet/fuel-stores/$tank/recharge", ['supplier_id' => $puma, 'date' => now()->subDays(30)->toDateString(), 'litres' => 8000, 'unit_price' => 3100, 'department_id' => $dept, 'budget_item_id' => $item], 'transport');
        if ($rcId = $rc['data']['id'] ?? null) {
            $d->api('POST', "fleet/fuel-recharges/$rcId/receive", ['litres' => 7980, 'date' => now()->subDays(29)->toDateString(), 'delivery_note' => 'DN-PUMA-5521'], 'transport');
        }
        $km = ['T 512 DKL' => 148200, 'T 744 DPR' => 96400, 'T 109 DGB' => 187650, 'T 233 DXA' => 54300, 'T 881 DCE' => 212400, 'T 340 EAB' => 38900];
        foreach ([0, 1] as $round) {
            foreach ($units as $reg => $unit) {
                $km[$reg] += 600 + $round * 250;
                $driver = \Modules\Fleet\Models\Assignment::where('fleet_unit_id', $unit)->whereNull('ends_on')->first()?->driver?->employee?->user;
                $id = $d->id($d->api('POST', 'fleet/fuel', ['fleet_unit_id' => $unit, 'fleet_fuel_store_id' => $tank, 'litres' => 60 + $round * 10, 'reading' => $km[$reg]], $driver ?? 'transport'));
                if ($id && $round === 0) {
                    $d->settle('fuel-request', fn ($i) => $i->subject_id !== $id);
                    $d->api('POST', "fleet/fuel/$id/issue", ['litres' => 60, 'reading' => $km[$reg]], 'transport');
                }
            }
        }
    }

    private function maintenance(array $units): void
    {
        $d = $this->demo;
        $dept = $this->dept('Transport');
        $item = BudgetItem::where('department_id', $dept)->where('account_id', Account::where('code', '5320')->value('id'))->value('id');
        foreach ([['T 512 DKL', 'corrective', 'Brake pads worn, squeaking', 'done'], ['T 881 DCE', 'corrective', 'Clutch slipping on hills', 'inspection'], ['T 233 DXA', 'corrective', 'Air conditioner not cooling', 'pending']] as [$reg, $kind, $desc, $stage]) {
            $id = $d->id($d->api('POST', 'fleet/maintenance', ['fleet_unit_id' => $units[$reg], 'kind' => $kind, 'description' => $desc], 'transport'));
            if (! $id || $stage === 'pending') {
                continue;
            }
            $d->settle('maintenance-request', fn ($i) => $i->subject_id !== $id);
            $d->api('POST', "fleet/maintenance/$id/inspect", ['findings' => $desc.' - parts needed', 'workshop' => 'Own workshop', 'department_id' => $dept, 'budget_item_id' => $item, 'lines' => [
                ['kind' => 'parts', 'description' => 'Spare parts', 'quantity' => 1, 'unit_cost' => 450000], ['kind' => 'labour', 'description' => 'Labour', 'quantity' => 1, 'unit_cost' => 120000]]], 'transport');
            if ($stage === 'done') {
                $d->settle('maintenance-inspection');
                $d->api('POST', "fleet/maintenance/$id/start", ['date' => now()->subDays(6)->toDateString()], 'transport');
                $d->api('POST', "fleet/maintenance/$id/complete", ['completed_on' => now()->subDays(5)->toDateString(), 'reading' => 148900, 'actual_cost' => 560000, 'invoice_reference' => 'WS-2231'], 'transport');
            }
        }
    }
}
