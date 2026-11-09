<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Http\Requests\StoreModuleLockRequest;
use Modules\Admin\Http\Resources\ModuleLockResource;
use Modules\Core\Models\ModuleLock;
use Modules\Core\Services\AccessService;

/**
 * Block a module for a branch or for one user, inside the company.
 *
 * @tags Admin: Module locks
 */
class ModuleLockController extends Controller
{
    /**
     * List module locks.
     */
    public function index(): AnonymousResourceCollection
    {
        return ModuleLockResource::collection(ModuleLock::with(['branch', 'user'])->latest('id')->get());
    }

    /**
     * Lock a module.
     */
    public function store(StoreModuleLockRequest $request, AccessService $access)
    {
        $data = $request->validated();
        $exists = ModuleLock::where('module_slug', $data['module_slug'])
            ->where('company_branch_id', $data['company_branch_id'] ?? null)->where('user_id', $data['user_id'] ?? null)->exists();
        if ($exists) {
            throw ValidationException::withMessages(['module_slug' => 'This module is already locked for them.']);
        }
        $lock = ModuleLock::create($data + ['locked_by' => $request->user()->id]);
        $access->forget();

        return (new ModuleLockResource($lock->load(['branch', 'user'])))->response()->setStatusCode(201);
    }

    /**
     * Unlock a module.
     */
    public function destroy(ModuleLock $moduleLock, AccessService $access): Response
    {
        $moduleLock->delete();
        $access->forget();

        return response()->noContent();
    }
}
