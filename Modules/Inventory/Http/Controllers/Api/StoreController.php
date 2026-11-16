<?php

namespace Modules\Inventory\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Actions\ManageStores;
use Modules\Inventory\Http\Requests\StoreBinRequest;
use Modules\Inventory\Http\Requests\StoreRequest;
use Modules\Inventory\Http\Requests\StoreUsersRequest;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\StoreBin;
use Modules\Inventory\Support\InventoryAccess;

/**
 * @tags Inventory: stores
 */
class StoreController extends Controller
{
    public function __construct(private readonly InventoryAccess $access, private readonly ManageStores $stores) {}

    /** @response array{data: list<array{id: int, code: string, name: string, kind: string, kind_label: string, branch: string|null, department: string|null, location: string|null, is_active: bool, officers: int, bins: int, mine: bool}>} */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $mine = $this->access->stores($request->user());

        return response()->json(['data' => Store::with(['branch', 'department'])->withCount(['users', 'bins'])->orderBy('name')->get()->map(fn (Store $s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'kind_label' => Store::KINDS[$s->kind] ?? $s->kind, 'branch' => $s->branch?->name,
            'department' => $s->department?->name, 'location' => $s->location, 'is_active' => (bool) $s->is_active, 'officers' => (int) $s->users_count, 'bins' => (int) $s->bins_count,
            'mine' => $mine === null || in_array($s->id, $mine, true),
        ])->values()]);
    }

    /** @response array{data: array{id: int, code: string, name: string, kind: string, kind_label: string, company_branch_id: int|null, branch: string|null, department_id: int|null, department: string|null, location: string|null, is_active: bool, users: list<array{user_id: int, name: string|null, role: string}>, bins: list<array{id: int, code: string, name: string, parent_id: int|null, parent: string|null}>, can: array{edit: bool}}} */
    public function show(Request $request, Store $store): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $s = $store->load(['branch', 'department', 'users', 'bins.parent']);

        return response()->json(['data' => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'kind_label' => Store::KINDS[$s->kind] ?? $s->kind, 'company_branch_id' => $s->company_branch_id,
            'branch' => $s->branch?->name, 'department_id' => $s->department_id, 'department' => $s->department?->name, 'location' => $s->location, 'is_active' => (bool) $s->is_active,
            'users' => $s->users->map(fn (User $u) => ['user_id' => $u->id, 'name' => $u->display_name, 'role' => (string) $u->pivot->role])->values(),
            'bins' => $s->bins->map(fn (StoreBin $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'parent_id' => $b->parent_id, 'parent' => $b->parent?->code])->values(),
            'can' => ['edit' => $this->access->can($request->user(), 'stores')],
        ]]);
    }

    /** @response array{data: array{id: int}} */
    public function store(StoreRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'stores'), 403);
        $this->unique($request->validated('code'), null);
        $s = $this->stores->save(null, $request->validated());

        return response()->json(['data' => ['id' => $s->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function update(StoreRequest $request, Store $store): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'stores'), 403);
        $this->unique($request->validated('code'), $store->id);
        $s = $this->stores->save($store, $request->validated());

        return response()->json(['data' => ['id' => $s->id]]);
    }

    public function users(StoreUsersRequest $request, Store $store): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'stores'), 403);
        $this->stores->users($store, $request->validated('users'));

        return response()->json(['message' => 'Store staff saved.']);
    }

    /** @response array{data: array{id: int}} */
    public function storeBin(StoreBinRequest $request, Store $store): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'stores'), 403);
        if ($store->bins()->where('code', $request->validated('code'))->exists()) {
            throw ValidationException::withMessages(['code' => 'This location code is already used in the store.']);
        }
        $b = $this->stores->addBin($store, $request->validated());

        return response()->json(['data' => ['id' => $b->id]], 201);
    }

    public function destroyBin(Request $request, Store $store, StoreBin $bin): JsonResponse
    {
        abort_unless($bin->store_id === $store->id, 404);
        abort_unless($this->access->can($request->user(), 'stores'), 403);
        $this->stores->deleteBin($bin);

        return response()->json(['message' => 'Location removed.']);
    }

    /**
     * Users who can be store officers or managers.
     *
     * @response array{data: list<array{id: int, name: string|null, email: string}>}
     */
    #[QueryParameter('search', type: 'string')]
    public function staff(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'stores'), 403);
        $s = (string) $request->query('search', '');

        return response()->json(['data' => User::with('employee')->where('company_id', $request->user()->company_id)->where('is_active', true)
            ->when($s !== '', fn ($q) => $q->where(fn ($q) => $q->where('username', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
                ->orWhereHas('employee', fn ($e) => $e->where('fname', 'like', "%{$s}%")->orWhere('lname', 'like', "%{$s}%"))))
            ->orderBy('email')->limit(20)->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->display_name, 'email' => $u->email])->values()]);
    }

    private function unique(string $code, ?int $except): void
    {
        if (Store::where('code', $code)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['code' => "The code {$code} is already used."]);
        }
    }
}
