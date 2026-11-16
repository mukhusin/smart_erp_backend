<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Http\Requests\AccountRequest;
use Modules\Accounting\Http\Requests\ImportAccountsRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Support\AccountImport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Accounting: setup
 */
/**
 * @tags Accounting: chart of accounts
 */
class AccountController extends Controller
{
    public function accounts(): JsonResponse
    {
        $totals = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('account_id');
        $accounts = Account::orderBy('code')->get();
        $byId = $accounts->keyBy('id');

        return response()->json(['data' => $accounts->map(fn (Account $a) => $this->accountRow($a, $byId, $totals[$a->id] ?? null))->values()]);
    }

    public function storeAccount(AccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $account = Account::create($data + ['is_budgetable' => $data['is_budgetable'] ?? in_array($data['type'], ['expense', 'revenue'], true), 'is_postable' => $data['is_postable'] ?? true, 'is_active' => true]);
        $this->guardParent($account);

        return response()->json(['data' => ['id' => $account->id]], 201);
    }

    public function updateAccount(AccountRequest $request, Account $account): JsonResponse
    {
        $data = $request->validated();
        $used = $account->lines()->exists();
        if ($used && ($data['type'] !== $account->type || (array_key_exists('is_postable', $data) && ! $data['is_postable']))) {
            throw ValidationException::withMessages(['type' => 'This account has entries; its type cannot change and it must stay postable.']);
        }
        $account->update($data);
        $this->guardParent($account);

        return response()->json(['data' => ['id' => $account->id]]);
    }

    public function destroyAccount(Account $account): JsonResponse
    {
        if ($account->lines()->exists() || $account->children()->exists() || CashBankAccount::where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['account' => 'This account is used (entries, sub-accounts or a bank account); switch it off instead.']);
        }
        $account->delete();

        return response()->json(['message' => 'Account deleted.']);
    }

    public function accountsTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['code', 'name', 'type', 'parent_code', 'gfs_code', 'postable', 'budgetable', 'description']);
            foreach ([['1000', 'Assets', 'asset', '', '', 'no', 'no', ''], ['1100', 'Cash at bank', 'asset', '1000', '', 'yes', 'no', 'Main bank account'],
                ['5000', 'Expenses', 'expense', '', '', 'no', 'no', ''], ['5100', 'Medical supplies', 'expense', '5000', '', 'yes', 'yes', '']] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'chart-of-accounts-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function importAccounts(ImportAccountsRequest $request, AccountImport $import): JsonResponse
    {
        return response()->json(['data' => $import->run($request->file('file')->getRealPath(), $request->boolean('dry_run'))]);
    }

    private function guardParent(Account $account): void
    {
        $seen = [$account->id];
        $p = $account->parent;
        while ($p) {
            if (in_array($p->id, $seen, true)) {
                throw ValidationException::withMessages(['parent_id' => 'An account cannot be under itself.']);
            }
            $seen[] = $p->id;
            $p = $p->parent;
        }
    }

    private function accountRow(Account $a, $byId, $t): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'gfs_code' => $a->gfs_code, 'name' => $a->name, 'type' => $a->type, 'type_label' => Account::TYPES[$a->type] ?? $a->type,
            'parent_id' => $a->parent_id, 'parent' => $a->parent_id && isset($byId[$a->parent_id]) ? $byId[$a->parent_id]->label() : null,
            'is_postable' => (bool) $a->is_postable, 'is_budgetable' => (bool) $a->is_budgetable, 'is_active' => (bool) $a->is_active, 'description' => $a->description,
            'balance' => $t ? $a->balance((float) $t->d, (float) $t->c) : 0.0, 'has_entries' => (bool) $t,
        ];
    }
}
