<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Requests\FrameworkRequest;
use Modules\Accounting\Models\AccountingPolicyChange;
use Modules\Accounting\Services\AccountingPolicies;
use Modules\Accounting\Support\FrameworkCatalog;

/**
 * @tags Accounting: setup
 */
class FrameworkController extends Controller
{
    public function __construct(private readonly AccountingPolicies $policies) {}

    /**
     * The accounting framework in force, every profile with its allowed choices, the statement wording and the change history.
     *
     * @response array{data: array{framework: string, framework_label: string, framework_name: string|null, policies: array<string, string>, wording: array<string, string>, has_postings: bool, allowed_starts: list<string>}, profiles: list<array{key: string, label: string, description: string, allowed: array<string, list<string>>, defaults: array<string, string>}>, policies: list<array{key: string, label: string, options: list<array{value: string, label: string}>}>, history: list<array{id: int, framework: string, framework_label: string, framework_name: string|null, policies: array<string, string>, effective_from: string, reason: string|null, changed_by: string|null, created_at: string}>}
     */
    public function show(Request $request): JsonResponse
    {
        $now = $this->policies->at();

        return response()->json([
            'data' => $now + ['framework_label' => FrameworkCatalog::PROFILES[$now['framework']]['label'], 'wording' => $this->policies->wording(),
                'has_postings' => $this->policies->hasPostings(), 'allowed_starts' => $this->policies->allowedStarts()],
            'profiles' => collect(FrameworkCatalog::PROFILES)->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label'], 'description' => $p['description'],
                'allowed' => FrameworkCatalog::allowed($key), 'defaults' => FrameworkCatalog::defaults($key)])->values(),
            'policies' => collect(FrameworkCatalog::POLICIES)->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label'],
                'options' => collect($p['options'])->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()])->values(),
            'history' => AccountingPolicyChange::with('user')->orderByDesc('effective_from')->orderByDesc('id')->get()->map(fn ($c) => ['id' => $c->id, 'framework' => $c->framework,
                'framework_label' => FrameworkCatalog::PROFILES[$c->framework]['label'] ?? $c->framework, 'framework_name' => $c->framework_name, 'policies' => $c->policies,
                'effective_from' => $c->effective_from->toDateString(), 'reason' => $c->reason, 'changed_by' => $c->user?->display_name, 'created_at' => $c->created_at->toIso8601String()])->values(),
        ]);
    }

    /** @response array{data: array{id: int, effective_from: string}} */
    public function update(FrameworkRequest $request): JsonResponse
    {
        $c = $this->policies->change($request->validated(), $request->user());

        return response()->json(['data' => ['id' => $c->id, 'effective_from' => $c->effective_from->toDateString()]]);
    }
}
