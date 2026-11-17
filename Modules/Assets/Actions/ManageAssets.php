<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Services\PostingService;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetSetting;
use Modules\Assets\Services\Depreciation;
use Modules\Assets\Workflow\AssetRegisterProcess;
use Modules\Inventory\Models\ItemCategory;
use Modules\Procurement\Models\GoodsReceipt;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageAssets
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly PostingService $posting, private readonly Depreciation $depreciation) {}

    public function nextNumber(ItemCategory $category, string $date): string
    {
        $pattern = AssetSetting::current()->number_pattern ?: '{CAT}-{YEAR}-{SEQ}';
        $prefix = str_replace(['{CAT}', '{YEAR}'], [$category->code, Carbon::parse($date)->format('Y')], $pattern);
        [$head] = explode('{SEQ}', $prefix);
        $last = Asset::where('number', 'like', $head.'%')->lockForUpdate()->pluck('number')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', substr($n, strlen($head), 6)))->max() ?? 0;

        return str_replace('{SEQ}', str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT), $prefix);
    }

    private function category(int $id): ItemCategory
    {
        $c = ItemCategory::where('is_active', true)->find($id);
        if (! $c || ! in_array($c->type, ['asset', 'intangible', 'wip'], true)) {
            throw ValidationException::withMessages(['item_category_id' => 'Choose an active fixed asset, intangible or work-in-progress category.']);
        }

        return $c;
    }

    /** @return list<Asset> one record per unit when quantity is more than one */
    public function create(array $data, User $by): array
    {
        $category = $this->category((int) $data['item_category_id']);
        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $serials = array_values(array_filter(array_map('trim', (array) ($data['serials'] ?? []))));

        return DB::transaction(function () use ($data, $by, $category, $quantity, $serials) {
            $out = [];
            for ($i = 0; $i < $quantity; $i++) {
                $out[] = Asset::create($this->fields($data, $category) + ['number' => $this->nextNumber($category, $data['acquisition_date']), 'status' => 'draft', 'created_by' => $by->id,
                    'serial_number' => $serials[$i] ?? ($quantity === 1 ? ($data['serial_number'] ?? null) : null)]);
            }

            return $out;
        });
    }

    private function fields(array $data, ItemCategory $category): array
    {
        $cost = round((float) $data['cost'], 2);

        return [
            'name' => $data['name'], 'description' => $data['description'] ?? null, 'item_category_id' => $category->id, 'item_id' => $data['item_id'] ?? null,
            'asset_class' => $data['asset_class'] ?? ($category->type === 'intangible' ? 'software' : 'equipment'), 'details' => $data['details'] ?? null,
            'acquisition_type' => $data['acquisition_type'] ?? 'purchase', 'acquisition_date' => $data['acquisition_date'], 'in_use_date' => $data['in_use_date'] ?? null, 'cost' => $cost,
            'opening_depreciation' => round((float) ($data['opening_depreciation'] ?? 0), 2),
            'useful_life_months' => $data['useful_life_months'] ?? $category->useful_life_months,
            'residual_value' => array_key_exists('residual_value', $data) && $data['residual_value'] !== null ? round((float) $data['residual_value'], 2) : round($cost * (float) $category->residual_percent / 100, 2),
            'fund_source_id' => $data['fund_source_id'] ?? null, 'supplier_id' => $data['supplier_id'] ?? null, 'tag' => $data['tag'] ?? null,
            'company_branch_id' => $data['company_branch_id'] ?? null, 'department_id' => $data['department_id'] ?? null, 'employee_id' => $data['employee_id'] ?? null,
            'location' => $data['location'] ?? null, 'condition' => $data['condition'] ?? 'new',
        ];
    }

    public function update(Asset $asset, array $data): Asset
    {
        if (! $asset->editable()) {
            throw ValidationException::withMessages(['asset' => 'Approved assets change through an improvement, revaluation, life review, transfer or disposal.']);
        }
        $asset->update($this->fields($data, $this->category((int) $data['item_category_id'])) + ['serial_number' => $data['serial_number'] ?? null]);

        return $asset->refresh();
    }

    public function delete(Asset $asset): void
    {
        if (! $asset->editable()) {
            throw ValidationException::withMessages(['asset' => 'Only a draft or returned asset can be deleted.']);
        }
        $asset->delete();
    }

    private function check(Asset $asset): void
    {
        $asset->loadMissing('category');
        $s = AssetSetting::current();
        $need = match ($asset->acquisition_type) {
            'donation', 'transfer' => ['donation_account_id' => 'the account for donated and transferred-in assets'],
            'opening' => ['opening_account_id' => 'the opening balance account'],
            default => [],
        };
        foreach ($need as $field => $label) {
            if (! $s->{$field}) {
                throw ValidationException::withMessages(['asset' => "Set {$label} in Fixed Assets → Settings first."]);
            }
        }
        if ($asset->acquisition_type !== 'purchase' && ! $asset->category->asset_account_id) {
            throw ValidationException::withMessages(['asset' => "Category {$asset->category->name} has no asset cost account."]);
        }
        if ($asset->opening_depreciation > 0 && ! $asset->category->accumulated_account_id) {
            throw ValidationException::withMessages(['asset' => "Category {$asset->category->name} has no accumulated depreciation account."]);
        }
        if ($asset->cost <= 0) {
            throw ValidationException::withMessages(['asset' => "{$asset->number}: the cost must be more than zero."]);
        }
        if ($asset->opening_depreciation > $asset->cost) {
            throw ValidationException::withMessages(['asset' => "{$asset->number}: depreciation before go-live cannot exceed the cost."]);
        }
    }

    public function submit(Asset $asset, User $by): Asset
    {
        if (! $asset->editable()) {
            throw ValidationException::withMessages(['asset' => 'This asset is already submitted.']);
        }
        $this->check($asset);

        return DB::transaction(function () use ($asset, $by) {
            $asset->update(['status' => 'pending', 'decision_comment' => null]);
            if (! $this->workflow->start(AssetRegisterProcess::KEY, $asset, $by)) {
                $this->approved($asset);
            }

            return $asset->refresh();
        });
    }

    /** Opening assets are dated on the first day of the first fiscal year (go-live). */
    public function openingDate(): string
    {
        return FiscalYear::orderBy('start_date')->value('start_date')?->toDateString() ?? today()->toDateString();
    }

    public function approved(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $asset->loadMissing(['category', 'creator']);
            $s = AssetSetting::current();
            $c = $asset->category;
            $opening = $asset->acquisition_type === 'opening';
            $date = $opening ? $this->openingDate() : $asset->acquisition_date->toDateString();
            $journal = null;
            if ($asset->acquisition_type !== 'purchase') {
                $credit = $opening ? $s->opening_account_id : $s->donation_account_id;
                $lines = [['account_id' => $c->asset_account_id, 'debit' => $asset->cost, 'description' => "{$asset->number} {$asset->name}", 'department_id' => $asset->department_id, 'fund_source_id' => $asset->fund_source_id],
                    ['account_id' => $credit, 'credit' => $asset->cost, 'description' => Asset::ACQUISITION[$asset->acquisition_type], 'fund_source_id' => $asset->fund_source_id]];
                if ($asset->opening_depreciation > 0) {
                    $lines[] = ['account_id' => $s->opening_account_id, 'debit' => $asset->opening_depreciation, 'description' => "{$asset->number} depreciation before go-live"];
                    $lines[] = ['account_id' => $c->accumulated_account_id, 'credit' => $asset->opening_depreciation, 'description' => "{$asset->number} depreciation before go-live"];
                }
                $journal = $this->posting->post($date, "{$asset->number} {$asset->name}", $lines, $asset->creator, 'asset', $asset);
            }
            $asset->entries()->create(['date' => $opening ? $date : $asset->acquisition_date->toDateString(), 'kind' => 'acquisition', 'cost' => $asset->cost,
                'description' => Asset::ACQUISITION[$asset->acquisition_type], 'journal_entry_id' => $journal?->id]);
            if ($asset->opening_depreciation > 0) {
                $asset->entries()->create(['date' => $date, 'kind' => 'opening', 'depreciation' => $asset->opening_depreciation, 'description' => 'Depreciation before go-live', 'journal_entry_id' => $journal?->id]);
            }
            $status = $asset->employee_id || $asset->department_id ? 'active' : 'registered';
            $asset->update(['status' => $status, 'journal_entry_id' => $journal?->id, 'approved_at' => now(), 'in_use_date' => $asset->in_use_date ?? ($status === 'active' ? $asset->acquisition_date : null),
                'dep_charged_to' => $opening ? Carbon::parse($date)->subDay()->toDateString() : null]);
            if ($asset->employee_id || $asset->department_id) {
                $asset->assignments()->create(['department_id' => $asset->department_id, 'employee_id' => $asset->employee_id, 'location' => $asset->location,
                    'from_date' => $asset->in_use_date ?? $asset->acquisition_date, 'condition_out' => $asset->condition, 'reason' => 'On registration', 'assigned_by' => $asset->created_by]);
            }
            $this->depreciation->begin($asset->refresh(), $opening ? Carbon::parse($date) : null);
        });
    }

    public function rejected(Asset $asset, ?string $comment, bool $withdrawn): void
    {
        $asset->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
    }

    public function withdraw(Asset $asset, User $by): Asset
    {
        if ($asset->status !== 'pending' || $asset->created_by !== $by->id) {
            throw ValidationException::withMessages(['asset' => 'Only the person who submitted it can withdraw it while it waits.']);
        }
        $instance = $asset->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($asset, null, true);

        return $asset->refresh();
    }

    /** Hand an asset to a department and/or employee; the previous holder's record is closed. */
    public function assign(Asset $asset, array $data, User $by): Asset
    {
        if (! in_array($asset->status, ['registered', 'active', 'stored', 'suspended'], true)) {
            throw ValidationException::withMessages(['asset' => 'This asset cannot be assigned now.']);
        }
        if (empty($data['department_id']) && empty($data['employee_id'])) {
            throw ValidationException::withMessages(['department_id' => 'Choose a department or an employee.']);
        }

        return DB::transaction(function () use ($asset, $data, $by) {
            $date = $data['date'] ?? today()->toDateString();
            $asset->assignments()->whereNull('to_date')->update(['to_date' => $date, 'condition_in' => $data['condition'] ?? $asset->condition, 'returned_from' => 'reassigned', 'received_by' => $by->id]);
            $department = $data['department_id'] ?? \App\Models\Employee::find($data['employee_id'] ?? null)?->department_id;
            $asset->assignments()->create(['department_id' => $department, 'employee_id' => $data['employee_id'] ?? null, 'location' => $data['location'] ?? $asset->location,
                'from_date' => $date, 'condition_out' => $data['condition'] ?? $asset->condition, 'reason' => $data['reason'] ?? null, 'assigned_by' => $by->id]);
            $asset->update(['department_id' => $department, 'employee_id' => $data['employee_id'] ?? null, 'location' => $data['location'] ?? $asset->location,
                'condition' => $data['condition'] ?? $asset->condition, 'status' => 'active', 'in_use_date' => $asset->in_use_date ?? $date]);
            $this->depreciation->begin($asset->refresh());

            return $asset;
        });
    }

    public function returnAsset(Asset $asset, array $data, User $by): Asset
    {
        if (! $asset->employee_id && ! $asset->department_id) {
            throw ValidationException::withMessages(['asset' => 'Nobody holds this asset.']);
        }

        return DB::transaction(function () use ($asset, $data, $by) {
            $date = $data['date'] ?? today()->toDateString();
            $asset->assignments()->whereNull('to_date')->update(['to_date' => $date, 'condition_in' => $data['condition'], 'returned_from' => $asset->employee_id ? 'employee' : 'department', 'received_by' => $by->id]);
            $asset->update(['employee_id' => null, 'department_id' => $data['keep_department'] ?? false ? $asset->department_id : null, 'condition' => $data['condition'],
                'location' => $data['location'] ?? $asset->location, 'status' => ($data['keep_department'] ?? false) ? 'active' : 'stored']);

            return $asset->refresh();
        });
    }

    public function setStatus(Asset $asset, string $status): Asset
    {
        if (! in_array($asset->status, ['registered', 'active', 'stored', 'suspended'], true) || ! in_array($status, ['active', 'stored', 'suspended'], true)) {
            throw ValidationException::withMessages(['status' => 'This status change is not allowed.']);
        }
        $asset->update(['status' => $status, 'in_use_date' => $asset->in_use_date ?? ($status === 'active' ? today() : null)]);
        $this->depreciation->begin($asset->refresh());

        return $asset;
    }

    /** One record per unit for every asset line of an accepted delivery (the GRN journal already put the cost on the asset account). */
    public function fromGoodsReceipt(GoodsReceipt $grn): int
    {
        if (Asset::where('goods_receipt_id', $grn->id)->exists()) {
            return 0;
        }
        $grn->loadMissing(['lines.orderLine.item.category', 'lines.orderLine.budgetItem', 'order.requisition', 'creator']);
        $count = 0;
        foreach ($grn->lines as $l) {
            $item = $l->orderLine?->item;
            $category = $item?->category;
            if (! $category || ! in_array($category->type, ['asset', 'intangible', 'wip'], true) || $l->accepted_quantity <= 0 || $l->amount <= 0) {
                continue;
            }
            $units = max(1, (int) round($l->accepted_quantity));
            $unitCost = round($l->amount / $units, 2);
            $serials = array_values((array) ($l->serials ?? []));
            for ($i = 0; $i < $units; $i++) {
                $cost = $i === $units - 1 ? round($l->amount - $unitCost * ($units - 1), 2) : $unitCost;
                $asset = Asset::create(['number' => $this->nextNumber($category, $grn->received_on->toDateString()), 'name' => $item->name, 'item_category_id' => $category->id, 'item_id' => $item->id,
                    'asset_class' => $category->type === 'intangible' ? 'software' : 'equipment', 'acquisition_type' => 'purchase', 'acquisition_date' => $grn->received_on, 'cost' => $cost,
                    'useful_life_months' => $category->useful_life_months, 'residual_value' => round($cost * (float) $category->residual_percent / 100, 2),
                    'fund_source_id' => $l->orderLine->budgetItem?->fund_source_id, 'supplier_id' => $grn->order?->supplier_id, 'goods_receipt_id' => $grn->id,
                    'serial_number' => $serials[$i] ?? null, 'department_id' => $grn->order?->requisition?->department_id, 'condition' => 'new', 'status' => 'registered',
                    'created_by' => $grn->created_by, 'approved_at' => now()]);
                $asset->entries()->create(['date' => $grn->received_on->toDateString(), 'kind' => 'acquisition', 'cost' => $cost, 'description' => "Received on {$grn->number}",
                    'journal_entry_id' => $grn->journal_entry_id, 'source_type' => $grn->getMorphClass(), 'source_id' => $grn->id]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Bulk upload (opening balances or many assets at once): each row becomes a draft; bad rows are reported.
     *
     * @return array{created: int, errors: list<string>}
     */
    public function import(string $path, User $by): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $created = 0;
        $errors = [];
        $n = 1;
        $categories = ItemCategory::whereIn('type', ['asset', 'intangible', 'wip'])->get()->keyBy(fn ($c) => strtolower($c->code));
        $departments = \App\Models\Department::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id]);
        while (($line = fgetcsv($handle)) !== false) {
            $n++;
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $r = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), null));
            $category = $categories[strtolower(trim((string) ($r['category_code'] ?? '')))] ?? null;
            try {
                if (! $category) {
                    throw ValidationException::withMessages(['row' => 'unknown category '.($r['category_code'] ?? '')]);
                }
                if (! is_numeric($r['cost'] ?? null) || (float) $r['cost'] <= 0) {
                    throw ValidationException::withMessages(['row' => 'the cost must be a number above zero']);
                }
                $date = Carbon::parse((string) ($r['acquisition_date'] ?? ''))->toDateString();
                $dept = trim((string) ($r['department'] ?? ''));
                if ($dept !== '' && ! isset($departments[strtolower($dept)])) {
                    throw ValidationException::withMessages(['row' => "unknown department {$dept}"]);
                }
                $type = in_array($r['acquisition_type'] ?? '', array_keys(Asset::ACQUISITION), true) ? $r['acquisition_type'] : 'opening';
                $this->create(['item_category_id' => $category->id, 'name' => trim((string) ($r['name'] ?? '')) ?: $category->name, 'description' => $r['description'] ?? null,
                    'asset_class' => array_key_exists($r['asset_class'] ?? '', Asset::CLASSES) ? $r['asset_class'] : null, 'acquisition_type' => $type, 'acquisition_date' => $date,
                    'in_use_date' => ! empty($r['in_use_date']) ? Carbon::parse($r['in_use_date'])->toDateString() : $date, 'cost' => (float) $r['cost'],
                    'opening_depreciation' => is_numeric($r['accumulated_depreciation'] ?? null) ? (float) $r['accumulated_depreciation'] : 0,
                    'useful_life_months' => is_numeric($r['useful_life_months'] ?? null) ? (int) $r['useful_life_months'] : null, 'serial_number' => $r['serial_number'] ?? null,
                    'tag' => $r['tag'] ?? null, 'department_id' => $dept !== '' ? $departments[strtolower($dept)] : null, 'location' => $r['location'] ?? null,
                    'condition' => array_key_exists($r['condition'] ?? '', Asset::CONDITIONS) ? $r['condition'] : 'good'], $by);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$n}: ".($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());
            }
        }
        fclose($handle);

        return ['created' => $created, 'errors' => $errors];
    }

    /** @return array{submitted: int, errors: list<string>} */
    public function submitDrafts(User $by): array
    {
        $done = 0;
        $errors = [];
        foreach (Asset::where('status', 'draft')->where('created_by', $by->id)->orderBy('id')->get() as $asset) {
            try {
                $this->submit($asset, $by);
                $done++;
            } catch (ValidationException $e) {
                $errors[] = collect($e->errors())->flatten()->first();
            }
        }

        return ['submitted' => $done, 'errors' => $errors];
    }
}
