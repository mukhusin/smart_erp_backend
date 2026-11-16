<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Accounting\Http\Requests\CashBankAccountRequest;
use Modules\Accounting\Http\Requests\FundSourceRequest;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\FundSource;

/**
 * @tags Accounting: setup
 */
/**
 * @tags Accounting: setup
 */
class MasterDataController extends Controller
{
    public function fundSources(): JsonResponse
    {
        return response()->json(['data' => FundSource::orderBy('code')->get()->map(fn ($f) => $this->fundRow($f))->values()]);
    }

    public function storeFundSource(FundSourceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->fundRow(FundSource::create($request->validated() + ['is_active' => true]))], 201);
    }

    public function updateFundSource(FundSourceRequest $request, FundSource $fundSource): JsonResponse
    {
        $fundSource->update($request->validated());

        return response()->json(['data' => $this->fundRow($fundSource)]);
    }

    public function cashBank(): JsonResponse
    {
        return response()->json(['data' => CashBankAccount::with('account')->orderBy('name')->get()->map(fn ($c) => $this->cashRow($c))->values()]);
    }

    public function storeCashBank(CashBankAccountRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->cashRow(CashBankAccount::create($request->validated() + ['is_active' => true])->load('account'))], 201);
    }

    public function updateCashBank(CashBankAccountRequest $request, CashBankAccount $cashBank): JsonResponse
    {
        $cashBank->update($request->validated());

        return response()->json(['data' => $this->cashRow($cashBank->load('account'))]);
    }

    private function fundRow(FundSource $f): array
    {
        return ['id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'kind' => $f->kind, 'kind_label' => FundSource::KINDS[$f->kind] ?? $f->kind, 'description' => $f->description, 'is_active' => (bool) $f->is_active];
    }

    private function cashRow(CashBankAccount $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'account' => $c->account ? ['id' => $c->account->id, 'code' => $c->account->code, 'name' => $c->account->name] : null,
            'bank_name' => $c->bank_name, 'account_number' => $c->account_number, 'currency' => $c->currency, 'is_active' => (bool) $c->is_active];
    }
}
