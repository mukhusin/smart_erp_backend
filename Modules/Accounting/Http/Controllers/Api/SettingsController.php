<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Modules\Accounting\Http\Requests\AccountingSettingsRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\FundSource;
use Modules\Tasks\Models\TaskActivity;

/**
 * @tags Accounting: setup
 */
/**
 * @tags Accounting: setup
 */
class SettingsController extends Controller
{
    public function settings(): JsonResponse
    {
        $s = AccountingSetting::current();

        return response()->json(['data' => ['use_gfs' => (bool) $s->use_gfs, 'budget_phasing' => (string) $s->budget_phasing, 'budget_control' => (string) $s->budget_control]]);
    }

    public function updateSettings(AccountingSettingsRequest $request): JsonResponse
    {
        $s = AccountingSetting::current();
        $s->fill($request->validated())->save();

        return $this->settings();
    }

    public function lookups(): JsonResponse
    {
        return response()->json(['data' => [
            'use_gfs' => (bool) AccountingSetting::current()->use_gfs,
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'gfs_code' => $a->gfs_code, 'name' => $a->name, 'type' => $a->type, 'is_budgetable' => (bool) $a->is_budgetable])->values(),
            'departments' => Department::whereNull('parent_id')->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'fund_sources' => FundSource::where('is_active', true)->orderBy('code')->get()->map(fn ($f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values(),
            'activities' => TaskActivity::where('is_active', true)->where('year', '>=', now()->year - 1)->orderBy('code')->orderBy('title')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'title' => $a->title, 'department_id' => (int) $a->department_id, 'year' => (int) $a->year])->values(),
            'cash_bank' => CashBankAccount::where('is_active', true)->orderBy('name')->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'account_id' => (int) $c->account_id])->values(),
        ]]);
    }
}
