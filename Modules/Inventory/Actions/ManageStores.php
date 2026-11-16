<?php

namespace Modules\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\StoreBin;

class ManageStores
{
    public function save(?Store $store, array $data): Store
    {
        $store ??= new Store(['is_active' => true]);
        $store->fill($data)->save();

        return $store;
    }

    /** @param list<array{user_id: int, role: string}> $users */
    public function users(Store $store, array $users): void
    {
        $store->users()->sync(collect($users)->mapWithKeys(fn ($u) => [(int) $u['user_id'] => ['role' => $u['role']]])->all());
    }

    public function addBin(Store $store, array $data): StoreBin
    {
        if (! empty($data['parent_id']) && ! $store->bins()->whereKey($data['parent_id'])->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Choose a location in this store.']);
        }

        return $store->bins()->create($data);
    }

    public function deleteBin(StoreBin $bin): void
    {
        DB::table('item_store_levels')->where('store_bin_id', $bin->id)->update(['store_bin_id' => null]);
        $bin->delete();
    }

    /** @param list<array{store_id: int, min_level: ?float, max_level: ?float, reorder_level: ?float, store_bin_id: ?int}> $levels */
    public function levels(Item $item, array $levels): void
    {
        DB::transaction(function () use ($item, $levels) {
            $item->levels()->delete();
            foreach ($levels as $l) {
                if (! empty($l['store_bin_id']) && ! StoreBin::where('store_id', $l['store_id'])->whereKey($l['store_bin_id'])->exists()) {
                    throw ValidationException::withMessages(['levels' => 'A bin must belong to its store.']);
                }
                $item->levels()->create($l);
            }
        });
    }
}
