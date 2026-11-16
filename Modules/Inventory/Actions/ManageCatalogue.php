<?php

namespace Modules\Inventory\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Unit;

class ManageCatalogue
{
    public function saveUnit(?Unit $unit, array $data): Unit
    {
        $unit ??= new Unit(['is_active' => true]);
        $unit->fill($data)->save();

        return $unit;
    }

    public function saveCategory(?ItemCategory $category, array $data): ItemCategory
    {
        if ($category && $category->type !== $data['type'] && $category->items()->exists()) {
            throw ValidationException::withMessages(['type' => 'The type cannot change once items use this category.']);
        }
        $category ??= new ItemCategory(['is_active' => true]);
        $category->fill($data)->save();

        return $category;
    }

    public function saveItem(?Item $item, array $data, User $by): Item
    {
        $category = ItemCategory::findOrFail($data['item_category_id']);
        foreach ($data['units'] ?? [] as $i => $u) {
            if ((int) $u['unit_id'] === (int) $data['unit_id']) {
                throw ValidationException::withMessages(["units.$i.unit_id" => 'The base unit is not a conversion of itself.']);
            }
        }

        return DB::transaction(function () use ($item, $data, $by, $category) {
            $item ??= new Item(['code' => ($data['code'] ?? null) ?: $this->nextCode($category), 'created_by' => $by->id, 'is_active' => true]);
            $item->fill(collect($data)->except(array_merge(['units'], empty($data['code']) ? ['code'] : []))->all() + ['type' => $category->type])->save();
            if (array_key_exists('units', $data)) {
                $item->units()->delete();
                foreach ($data['units'] ?? [] as $u) {
                    $item->units()->create(['unit_id' => $u['unit_id'], 'factor' => $u['factor']]);
                }
            }

            return $item->refresh();
        });
    }

    public function nextCode(ItemCategory $category): string
    {
        $prefix = strtoupper($category->code).'-';
        $last = Item::where('code', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('code')->value('code');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Rows from the item import file: code, name, category code, unit code, use, reorder level, min, max, description.
     *
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function import(array $rows, User $by): array
    {
        $categories = ItemCategory::get()->keyBy(fn ($c) => strtoupper($c->code));
        $units = Unit::get()->keyBy(fn ($u) => strtoupper($u->code));
        $created = $updated = 0;
        $errors = [];
        DB::transaction(function () use ($rows, $by, $categories, $units, &$created, &$updated, &$errors) {
            foreach ($rows as $n => $r) {
                $line = $n + 2;
                $name = trim((string) ($r['name'] ?? ''));
                $category = $categories[strtoupper(trim((string) ($r['category'] ?? '')))] ?? null;
                $unit = $units[strtoupper(trim((string) ($r['unit'] ?? '')))] ?? null;
                if ($name === '' || ! $category || ! $unit) {
                    $errors[] = "Line {$line}: ".($name === '' ? 'name is empty' : (! $category ? 'unknown category code' : 'unknown unit code')).'.';

                    continue;
                }
                $code = trim((string) ($r['code'] ?? ''));
                $item = $code !== '' ? Item::where('code', $code)->first() : null;
                $use = in_array($r['use'] ?? '', array_keys(Item::USES), true) ? $r['use'] : 'internal';
                $num = fn ($v) => is_numeric($v) ? (float) $v : null;
                $this->saveItem($item, ['code' => $code ?: null, 'name' => mb_substr($name, 0, 200), 'item_category_id' => $category->id, 'unit_id' => $unit->id, 'use' => $use,
                    'reorder_level' => $num($r['reorder_level'] ?? null), 'min_level' => $num($r['min_level'] ?? null), 'max_level' => $num($r['max_level'] ?? null),
                    'description' => ($r['description'] ?? '') ?: null], $by);
                $item ? $updated++ : $created++;
            }
        });

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }
}
