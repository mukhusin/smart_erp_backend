<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use Illuminate\Http\JsonResponse;

/**
 * @tags Admin: Module locks
 */
class BranchController extends Controller
{
    /**
     * List branches.
     *
     * @response array{data: list<array{id: int, name: string, is_headquarters: bool}>}
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => CompanyBranch::orderByDesc('is_headquarters')->orderBy('name')
            ->get(['id', 'name', 'is_headquarters'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'is_headquarters' => (bool) $b->is_headquarters])]);
    }
}
