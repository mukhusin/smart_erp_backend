<?php

namespace Database\Seeders\Demo;

use App\Models\Department;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Store;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;

class DemoSupplySeeder
{
    private const UNITS = [['PCS', 'Piece'], ['BOX', 'Box'], ['PKT', 'Packet'], ['BTL', 'Bottle'], ['VIAL', 'Vial'], ['REAM', 'Ream'], ['LTR', 'Litre'], ['PAIR', 'Pair'], ['ROLL', 'Roll'], ['KIT', 'Kit']];

    private const CATEGORIES = [
        ['MED', 'Medical supplies', 'store', '1310', '5100'], ['PHA', 'Pharmaceuticals', 'store', '1320', '5110'], ['LAB', 'Laboratory reagents', 'store', '1310', '5100'],
        ['STA', 'Stationery', 'store', '1330', '5400'], ['CLN', 'Cleaning materials', 'store', '1330', '5900'],
    ];

    private const ITEMS = [
        ['MED', 'Surgical gloves (sterile, size 7.5)', 'PAIR', 'BOX', 50, 400, 1200], ['MED', 'Examination gloves (medium)', 'PCS', 'BOX', 100, 2000, 180],
        ['MED', 'Disposable syringes 5 ml', 'PCS', 'BOX', 100, 3000, 150], ['MED', 'IV cannula 20G', 'PCS', 'BOX', 50, 800, 900], ['MED', 'Surgical face masks', 'PCS', 'BOX', 50, 2500, 120],
        ['MED', 'Gauze swabs 10x10 cm', 'PKT', null, 1, 300, 2500], ['MED', 'Cotton wool 500 g', 'ROLL', null, 1, 120, 6500], ['MED', 'Urinary catheter Foley 16Fr', 'PCS', 'BOX', 10, 150, 2800],
        ['MED', 'Suture vicryl 2/0', 'PCS', 'BOX', 12, 240, 9500], ['MED', 'Blood giving set', 'PCS', 'BOX', 25, 400, 2200],
        ['PHA', 'Paracetamol 500 mg tablets (1000)', 'BOX', null, 1, 60, 18000], ['PHA', 'Amoxicillin 500 mg capsules (100)', 'BOX', null, 1, 80, 14500],
        ['PHA', 'Ceftriaxone 1 g injection', 'VIAL', 'BOX', 10, 500, 3200], ['PHA', 'Metronidazole 500 mg IV 100 ml', 'BTL', null, 1, 300, 2600],
        ['PHA', 'Normal saline 0.9% 500 ml', 'BTL', 'BOX', 20, 1200, 1800], ['PHA', 'Artemether-lumefantrine (ALu) 24 tabs', 'PKT', null, 1, 400, 4200],
        ['PHA', 'Insulin soluble 10 ml', 'VIAL', null, 1, 80, 22000], ['PHA', 'Oxytocin 10 IU injection', 'VIAL', 'BOX', 10, 300, 1500],
        ['LAB', 'Malaria RDT kits (25)', 'KIT', null, 1, 60, 38000], ['LAB', 'Full blood count reagent', 'LTR', null, 1, 20, 185000],
        ['LAB', 'HIV rapid test kits (100)', 'KIT', null, 1, 25, 120000], ['LAB', 'Blood sample tubes EDTA', 'PCS', 'BOX', 100, 2000, 350],
        ['STA', 'A4 paper 80 gsm', 'REAM', 'BOX', 5, 100, 12500], ['STA', 'Patient file folders', 'PCS', 'PKT', 50, 1000, 600], ['STA', 'Printer toner HP 05A', 'PCS', null, 1, 10, 145000],
        ['STA', 'Ball pens (blue)', 'PCS', 'BOX', 50, 500, 300],
        ['CLN', 'Liquid soap 5 litres', 'LTR', null, 1, 100, 3500], ['CLN', 'Sodium hypochlorite (Jik) 5 litres', 'LTR', null, 1, 150, 3000], ['CLN', 'Toilet rolls', 'ROLL', 'PKT', 10, 600, 700],
    ];

    private const SUPPLIERS = [
        ['MedEquip Tanzania Ltd', 'company', '100-200-301', ['MED', 'LAB'], 'CRDB Bank'], ['Kilimanjaro Pharmaceuticals Ltd', 'company', '100-200-302', ['PHA'], 'NMB Bank'],
        ['Dar Surgical Supplies', 'company', '100-200-303', ['MED'], 'NBC Bank'], ['Shelys Pharmaceuticals', 'company', '100-200-304', ['PHA'], 'Stanbic Bank'],
        ['LabTech East Africa', 'company', '100-200-305', ['LAB'], 'CRDB Bank'], ['Office World Ltd', 'company', '100-200-306', ['STA'], 'Exim Bank'],
        ['Clean Pro Services', 'company', '100-200-307', ['CLN'], 'NMB Bank'], ['Mwananchi Stationers', 'individual', '100-200-308', ['STA'], 'Azania Bank'],
        ['Afya Medical Distributors', 'company', '100-200-309', ['MED', 'PHA'], 'Equity Bank'], ['Pwani Motors Ltd', 'company', '100-200-310', [], 'CRDB Bank'],
        ['TechHub Computers Ltd', 'company', '100-200-311', [], 'NMB Bank'], ['Ubora Builders Ltd', 'company', '100-200-312', [], 'NBC Bank'],
    ];

    private array $units = [];

    private array $cats = [];

    private array $stores = [];

    public function __construct(private readonly Demo $demo) {}

    public function run(): void
    {
        $this->catalogue();
        $this->stock();
        $this->suppliers();
        $this->purchases();
    }

    private function catalogue(): void
    {
        $d = $this->demo;
        $acc = Account::pluck('id', 'code');
        foreach (self::UNITS as [$c, $n]) {
            $this->units[$c] = $d->id($d->api('POST', 'inventory/units', ['code' => $c, 'name' => $n], 'store'));
        }
        foreach (self::CATEGORIES as [$c, $n, $t, $inv, $exp]) {
            $this->cats[$c] = $d->id($d->api('POST', 'inventory/categories', ['code' => $c, 'name' => $n, 'type' => $t, 'inventory_account_id' => $acc[$inv], 'expense_account_id' => $acc[$exp]], 'store'));
        }
        foreach ([['ICT', 'ICT equipment', 48, 10, 500000, '1550'], ['MEQ', 'Medical equipment', 96, 5, 1000000, '1520'], ['FUR', 'Furniture and fittings', 96, 5, 300000, '1540'],
            ['VEH', 'Motor vehicles', 60, 15, 5000000, '1530'], ['BLD', 'Buildings', 480, 0, 20000000, '1510']] as [$c, $n, $life, $res, $thr, $a]) {
            $this->cats[$c] = $d->id($d->api('POST', 'inventory/categories', ['code' => $c, 'name' => $n, 'type' => 'asset', 'useful_life_months' => $life, 'residual_percent' => $res,
                'capitalisation_threshold' => $thr, 'asset_account_id' => $acc[$a], 'depreciation_account_id' => $acc['5600'], 'accumulated_account_id' => $acc['1590']], 'store'));
        }
        foreach (self::ITEMS as [$cat, $name, $unit, $pack, $factor, $reorder]) {
            $d->api('POST', 'inventory/items', ['name' => $name, 'item_category_id' => $this->cats[$cat], 'use' => $cat === 'PHA' ? 'both' : 'internal', 'unit_id' => $this->units[$unit],
                'reorder_level' => $reorder, 'units' => $pack ? [['unit_id' => $this->units[$pack], 'factor' => $factor]] : []], 'store');
        }
    }

    private function stock(): void
    {
        $d = $this->demo;
        $acc = Account::pluck('id', 'code');
        foreach ([['MAIN', 'Main medical store', 'main'], ['PHARM', 'Pharmacy dispensing store', 'pharmacy'], ['GEN', 'General store', 'main'], ['WARD', 'Wards sub-store', 'sub'], ['TRANSIT', 'Goods in transit', 'transit']] as [$c, $n, $k]) {
            $this->stores[$c] = $d->id($d->api('POST', 'inventory/stores', ['code' => $c, 'name' => $n, 'kind' => $k], 'procmanager'));
        }
        foreach (['MAIN', 'GEN', 'TRANSIT'] as $s) {
            $d->api('PUT', "inventory/stores/{$this->stores[$s]}/users", ['users' => [['user_id' => $d->user('store')->id, 'role' => 'manager'], ['user_id' => $d->user('procmanager')->id, 'role' => 'officer']]], 'procmanager');
        }
        $d->api('PUT', "inventory/stores/{$this->stores['PHARM']}/users", ['users' => [['user_id' => $d->user('pharmacy')->id, 'role' => 'manager'], ['user_id' => $d->user('store')->id, 'role' => 'officer']]], 'procmanager');
        $d->api('PUT', "inventory/stores/{$this->stores['WARD']}/users", ['users' => [['user_id' => $d->user('nursing')->id, 'role' => 'manager'], ['user_id' => $d->user('nurse')->id, 'role' => 'officer']]], 'procmanager');
        $d->api('PUT', 'inventory/stock-settings', ['opening_account_id' => $acc['3100'], 'donation_account_id' => $acc['4300'], 'expiry_warning_days' => 90, 'slow_moving_days' => 180], 'procmanager');

        $items = Item::orderBy('id')->get();
        $main = $general = [];
        foreach ($items as $i => $item) {
            $row = self::ITEMS[$i] ?? null;
            if (! $row) {
                continue;
            }
            $qty = $row[5] * ($i % 5 === 0 ? 0.6 : 3);
            $line = ['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $row[6]];
            if (in_array($row[0], ['STA', 'CLN'], true)) {
                $general[] = $line;
            } else {
                $main[] = $line + ($row[0] === 'PHA' ? ['batch_no' => 'B'.(24000 + $i), 'expiry_date' => now()->addMonths($i % 4 === 0 ? 2 : 18)->toDateString()] : []);
            }
        }
        foreach ([['MAIN', $main], ['GEN', $general]] as [$s, $lines]) {
            $id = $d->id($d->api('POST', 'inventory/documents', ['kind' => 'receipt', 'reason' => 'opening', 'store_id' => $this->stores[$s], 'date' => '2026-07-01', 'lines' => $lines], 'store'));
            $id && $d->api('POST', "inventory/documents/$id/submit", [], 'store');
        }
        $d->settle('stock-receipt');

        $req = [['Nursing', 'nursing', 'MAIN', 'Ward consumables for the month', [0, 1, 2, 4]], ['Medical', 'medical', 'MAIN', 'Theatre consumables', [0, 3, 8]], ['Laboratory', 'lab', 'MAIN', 'Reagents for haematology', [18, 21]],
            ['Pharmacy', 'pharmacy', 'MAIN', 'Restock dispensing store', [10, 11, 12, 14]], ['Finance', 'finmanager', 'GEN', 'Office stationery', [22, 23, 25]], ['Human Resources', 'hrmanager', 'GEN', 'Stationery for recruitment', [22, 25]],
            ['ICT', 'ict', 'GEN', 'Printer toner', [24]], ['Estates', 'assets', 'GEN', 'Cleaning materials', [26, 27, 28]], ['Nursing', 'nurse', 'MAIN', 'Emergency unit restock', [1, 2, 3, 9]]];
        $made = [];
        foreach ($req as $n => [$dept, $as, $store, $purpose, $idx]) {
            $lines = array_map(fn ($k) => ['item_id' => $items[$k]->id, 'unit_id' => $items[$k]->unit_id, 'quantity' => max(1, round((self::ITEMS[$k][5] ?? 10) / 4))], $idx);
            $id = $d->id($d->api('POST', 'inventory/requests', ['store_id' => $this->stores[$store], 'department_id' => Department::where('name', $dept)->whereNull('parent_id')->value('id'), 'purpose' => $purpose, 'lines' => $lines], $as));
            if ($id) {
                $d->api('POST', "inventory/requests/$id/submit", [], $as);
                $made[] = [$id, $store, $as];
            }
        }
        $d->settle('store-requisition', keepLatest: 2);
        foreach (array_slice($made, 0, 5) as [$id, $store, $as]) {
            $r = $d->api('GET', "inventory/requests/$id", [], 'store');
            if (($r['data']['status'] ?? null) !== 'approved') {
                continue;
            }
            $lines = collect($r['data']['lines'] ?? [])->map(fn ($l) => ['item_id' => $l['item_id'] ?? $l['item']['id'] ?? null, 'quantity' => $l['approved_quantity'] ?? $l['quantity'], 'store_requisition_line_id' => $l['id']])
                ->filter(fn ($l) => $l['item_id'])->values()->all();
            $doc = $d->id($d->api('POST', 'inventory/documents', ['kind' => 'issue', 'store_id' => $this->stores[$store], 'store_requisition_id' => $id, 'date' => now()->subDays(5)->toDateString(), 'lines' => $lines], 'store'));
            if ($doc) {
                $d->api('POST', "inventory/documents/$doc/submit", [], 'store');
                $d->api('POST', "inventory/documents/$doc/confirm", [], $as);
            }
        }
        $pharm = $items->slice(10, 4)->values();
        $doc = $d->id($d->api('POST', 'inventory/documents', ['kind' => 'transfer', 'store_id' => $this->stores['MAIN'], 'to_store_id' => $this->stores['PHARM'], 'date' => now()->subDays(2)->toDateString(),
            'lines' => $pharm->map(fn ($it) => ['item_id' => $it->id, 'quantity' => 20])->all()], 'store'));
        if ($doc) {
            $d->api('POST', "inventory/documents/$doc/submit", [], 'store');
        }
    }

    private function suppliers(): void
    {
        $d = $this->demo;
        foreach (self::SUPPLIERS as $i => [$name, $kind, $tin, $cats, $bank]) {
            $id = $d->id($d->api('POST', 'procurement/suppliers', ['name' => $name, 'kind' => $kind, 'tin' => $tin, 'vrn' => $kind === 'company' ? '40-0'.(10000 + $i).'-B' : null,
                'categories' => array_values(array_filter(array_map(fn ($c) => $this->cats[$c] ?? null, $cats))), 'email' => 'sales@'.str($name)->slug().'.co.tz',
                'phone' => '+255 22 2'.(110000 + $i * 731), 'address' => ['Kariakoo', 'Mikocheni', 'Upanga', 'Kurasini'][$i % 4].', Dar es Salaam'], 'procofficer'));
            if ($id) {
                $d->api('POST', "procurement/suppliers/$id/bank-accounts", ['bank_name' => $bank, 'account_name' => $name, 'account_number' => (string) (150000000 + $i * 7717)], 'procofficer');
            }
        }
        $d->settle('supplier-bank-account');
    }

    private function purchases(): void
    {
        $d = $this->demo;
        $acc = Account::pluck('id', 'code');
        $d->api('PUT', 'procurement/settings', ['clearing_account_id' => $acc['2150'], 'direct_limit' => 2000000, 'min_quotes' => 2, 'match_tolerance_percent' => 2], 'procmanager');
        $d->api('PUT', 'payments/settings', ['payable_account_id' => $acc['2100'], 'withholding_account_id' => null, 'require_attachment' => false], 'finmanager');
        $items = Item::orderBy('id')->get();
        $sup = Supplier::pluck('id', 'name');
        $bank = CashBankAccount::where('name', 'CRDB main account')->value('id');
        $budgetItem = fn (string $dept, string $code) => BudgetItem::where('department_id', Department::where('name', $dept)->whereNull('parent_id')->value('id'))
            ->where('account_id', $acc[$code])->value('id');

        $plans = [
            ['Nursing', 'nursing', 'MAIN', 'Gloves and syringes for Q2', [[0, 400, 1250], [2, 3000, 160]], '5100', ['MedEquip Tanzania Ltd', 'Dar Surgical Supplies'], 'full'],
            ['Pharmacy', 'pharmacy', 'MAIN', 'Antibiotics restock', [[11, 60, 14800], [12, 500, 3300]], '5110', ['Kilimanjaro Pharmaceuticals Ltd', 'Shelys Pharmaceuticals'], 'full'],
            ['Laboratory', 'lab', 'MAIN', 'Malaria and HIV test kits', [[18, 40, 39000], [20, 10, 121000]], '5100', ['LabTech East Africa', 'MedEquip Tanzania Ltd'], 'received'],
            ['Finance', 'finmanager', 'GEN', 'Stationery for finance office', [[22, 60, 12800], [24, 4, 150000]], '5400', ['Office World Ltd', 'Mwananchi Stationers'], 'ordered'],
            ['Estates', 'assets', 'GEN', 'Cleaning materials', [[26, 100, 3600], [27, 120, 3100]], '5900', ['Clean Pro Services', 'Afya Medical Distributors'], 'awarded'],
            ['Medical', 'medical', 'MAIN', 'Sutures for theatre', [[8, 120, 9800]], '5100', ['Dar Surgical Supplies', 'Afya Medical Distributors'], 'approved'],
            ['ICT', 'ict', 'GEN', 'Toner cartridges', [[24, 6, 148000]], '5410', ['Office World Ltd', 'TechHub Computers Ltd'], 'pending'],
        ];
        foreach ($plans as $n => [$dept, $head, $store, $title, $lines, $code, $bidders, $stage]) {
            $budget = $budgetItem($dept, $code) ?? $budgetItem($dept, '5400');
            $req = $d->id($d->api('POST', 'procurement/requisitions', ['department_id' => Department::where('name', $dept)->whereNull('parent_id')->value('id'), 'store_id' => $this->storeId($store), 'title' => $title, 'kind' => 'normal',
                'lines' => array_map(fn ($l) => ['item_id' => $items[$l[0]]->id, 'description' => $items[$l[0]]->name, 'quantity' => $l[1], 'estimated_unit_cost' => $l[2], 'budget_item_id' => $budget], $lines)], $head));
            if (! $req) {
                continue;
            }
            $d->api('POST', "procurement/requisitions/$req/submit", [], $head);
            if ($stage === 'pending') {
                continue;
            }
            $d->settle('purchase-requisition', fn ($i) => $i->subject_id !== $req);
            if ($stage === 'approved') {
                continue;
            }
            $r = $d->api('GET', "procurement/requisitions/$req", [], 'procofficer');
            $reqLines = collect($r['data']['lines'] ?? [])->pluck('id')->all();
            $rfq = $d->id($d->api('POST', "procurement/requisitions/$req/rfqs", ['title' => $title, 'closing_date' => now()->subDays(20 - $n)->toDateString()], 'procofficer'));
            if (! $rfq) {
                continue;
            }
            $quotes = [];
            foreach ($bidders as $b => $supplier) {
                $quotes[] = $d->id($d->api('POST', "procurement/rfqs/$rfq/quotations", ['supplier_id' => $sup[$supplier], 'delivery_days' => 7 + $b * 5,
                    'lines' => array_map(fn ($id, $l) => ['purchase_requisition_line_id' => $id, 'unit_price' => round($l[2] * ($b ? 1.04 : 0.97))], $reqLines, $lines)], 'procofficer'));
            }
            $d->api('POST', "procurement/rfqs/$rfq/award", ['awards' => array_map(fn ($id) => ['purchase_requisition_line_id' => $id, 'quotation_id' => $quotes[0]], $reqLines), 'comment' => 'Lowest evaluated price'], 'procofficer');
            if ($stage === 'awarded') {
                continue;
            }
            $d->settle('quotation-award');
            $po = PurchaseOrder::where('purchase_requisition_id', $req)->value('id');
            if (! $po) {
                $this->demo->note("No order for requisition $req");

                continue;
            }
            $d->api('POST', "procurement/orders/$po/submit", [], 'procofficer');
            $d->settle('purchase-order');
            if ($stage === 'ordered') {
                continue;
            }
            $o = $d->api('GET', "procurement/orders/$po", [], 'store');
            $grnLines = collect($o['data']['lines'] ?? [])->map(fn ($l) => ['purchase_order_line_id' => $l['id'], 'delivered_quantity' => $l['quantity'], 'accepted_quantity' => $l['quantity']])->all();
            $grn = $d->id($d->api('POST', "procurement/orders/$po/receipts", ['delivery_note' => 'DN-'.(3100 + $n), 'received_on' => now()->subDays(12 - $n)->toDateString(), 'lines' => $grnLines], 'store'));
            if (! $grn) {
                continue;
            }
            $d->api('POST', "procurement/receipts/$grn/submit", [], 'store');
            $d->settle('goods-receipt');
            if ($stage === 'received') {
                continue;
            }
            $g = $d->api('GET', "procurement/receipts/$grn", [], 'accountant');
            $amount = $g['data']['value'] ?? $g['data']['total'] ?? collect($o['data']['lines'] ?? [])->sum(fn ($l) => $l['quantity'] * $l['unit_price']);
            $d->api('PUT', "procurement/receipts/$grn/invoice", ['supplier_invoice' => 'INV-'.(8800 + $n), 'invoice_date' => now()->subDays(10 - $n)->toDateString(), 'invoice_amount' => $amount], 'accountant');
            if ($bill = $g['data']['payment_request']['id'] ?? null) {
                $d->api('POST', "payments/requests/$bill/voucher", ['cash_bank_account_id' => $bank, 'date' => now()->subDays(8 - $n)->toDateString()], 'accountant');
                $d->settle('payment-voucher');
            }
        }
    }

    private function storeId(string $code): ?int
    {
        return $this->stores[$code] ?? Store::where('code', $code)->value('id');
    }
}
