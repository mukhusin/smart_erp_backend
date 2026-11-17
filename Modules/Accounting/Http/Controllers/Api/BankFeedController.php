<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Accounting\Models\BankFeedTransaction;
use Modules\Accounting\Models\BankReconciliation;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\BankFeed;
use Modules\Core\Services\AccessService;

/**
 * @tags Accounting: bank feeds
 */
class BankFeedController extends Controller
{
    public function __construct(private readonly BankFeed $feed) {}

    /**
     * Feed settings of a bank account (the token is never sent back) and its latest transactions.
     *
     * @response array{data: array{enabled: bool, checked_at: string|null, error: string|null, token_set: bool, config: array{url: string|null, token_header: string|null, params: array<string, string>, list_path: string|null, id_field: string|null, date_field: string|null, description_field: string|null, reference_field: string|null, amount_field: string|null, credit_field: string|null, debit_field: string|null, days: int}, recent: list<array{date: string, description: string|null, reference: string|null, amount: float, used: bool}>}}
     */
    public function show(CashBankAccount $cashBank): JsonResponse
    {
        $c = $cashBank->feed_config ?? [];

        return response()->json(['data' => [
            'enabled' => (bool) $cashBank->feed_enabled, 'checked_at' => $cashBank->feed_checked_at?->toIso8601String(), 'error' => $cashBank->feed_error, 'token_set' => ! empty($c['token']),
            'config' => ['url' => $c['url'] ?? null, 'token_header' => $c['token_header'] ?? null, 'params' => (object) ($c['params'] ?? []), 'list_path' => $c['list_path'] ?? null,
                'id_field' => $c['id_field'] ?? null, 'date_field' => $c['date_field'] ?? null, 'description_field' => $c['description_field'] ?? null,
                'reference_field' => $c['reference_field'] ?? null, 'amount_field' => $c['amount_field'] ?? null, 'credit_field' => $c['credit_field'] ?? null,
                'debit_field' => $c['debit_field'] ?? null, 'days' => (int) ($c['days'] ?? 7)],
            'recent' => BankFeedTransaction::where('cash_bank_account_id', $cashBank->id)->orderByDesc('date')->orderByDesc('id')->limit(20)->get()
                ->map(fn ($t) => ['date' => $t->date->toDateString(), 'description' => $t->description, 'reference' => $t->reference, 'amount' => $t->amount, 'used' => (bool) $t->bank_statement_line_id])->values(),
        ]]);
    }

    /** @response array{message: string} */
    public function update(Request $request, CashBankAccount $cashBank): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            /** Address of the bank's transactions API (https). */
            'url' => ['nullable', 'required_if:enabled,true', 'url:https,http', 'max:500'],
            /** Leave empty to keep the saved token. */
            'token' => ['nullable', 'string', 'max:2000'],
            'clear_token' => ['nullable', 'boolean'],
            /** Header that carries the token, e.g. X-API-Key; empty = Authorization: Bearer. */
            'token_header' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-]+$/'],
            /** Query parameters; values may use {from} and {to} (YYYY-MM-DD). */
            'params' => ['nullable', 'array', 'max:20'],
            'params.*' => ['nullable', 'string', 'max:200'],
            'list_path' => ['nullable', 'string', 'max:100'],
            'id_field' => ['nullable', 'string', 'max:100'],
            'date_field' => ['nullable', 'string', 'max:100'],
            'description_field' => ['nullable', 'string', 'max:100'],
            'reference_field' => ['nullable', 'string', 'max:100'],
            /** Signed amount (money in positive); or use credit_field and debit_field. */
            'amount_field' => ['nullable', 'string', 'max:100'],
            'credit_field' => ['nullable', 'string', 'max:100'],
            'debit_field' => ['nullable', 'string', 'max:100'],
            /** Days looked back on each pull. */
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $old = $cashBank->feed_config ?? [];
        $token = ! empty($data['clear_token']) ? null : (($data['token'] ?? '') !== '' ? $data['token'] : ($old['token'] ?? null));
        $config = collect($data)->only(BankFeed::FIELDS)->except('token')->map(fn ($v) => is_string($v) ? trim($v) : $v)->filter(fn ($v) => $v !== null && $v !== '')->all();
        $config['params'] = collect($data['params'] ?? [])->filter(fn ($v, $k) => trim((string) $k) !== '')->map(fn ($v) => (string) $v)->all();
        if ($token) {
            $config['token'] = $token;
        }
        $cashBank->forceFill(['feed_config' => $config, 'feed_enabled' => (bool) $data['enabled']])->save();

        return response()->json(['message' => 'Bank feed saved.']);
    }

    /**
     * Calls the bank with the saved settings and shows what would be read, without keeping anything.
     *
     * @response array{data: array{error: string|null, count: int, rows: list<array{external_id: string, date: string, description: string|null, reference: string|null, amount: float}>, raw: string|null}}
     */
    public function test(CashBankAccount $cashBank): JsonResponse
    {
        $days = (int) (($cashBank->feed_config ?? [])['days'] ?? 7);
        $r = $this->feed->fetch($cashBank, today()->subDays($days)->toDateString(), today()->toDateString());

        return response()->json(['data' => ['error' => $r['error'], 'count' => count($r['rows']), 'rows' => array_slice($r['rows'], 0, 10), 'raw' => $r['error'] ? Str::limit((string) $r['raw'], 2000) : null]]);
    }

    /** @response array{data: array{added: int, error: string|null}} */
    public function sync(Request $request, CashBankAccount $cashBank, AccessService $access): JsonResponse
    {
        abort_unless($access->can($request->user(), 'accounting.reconcile') || $access->can($request->user(), 'accounting.manage'), 403);

        return response()->json(['data' => $this->feed->sync($cashBank)]);
    }

    /** @response array{data: array{added: int, error: string|null}} */
    public function pull(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        abort_unless($reconciliation->editable(), 422, 'Only a draft or returned reconciliation can take statement lines.');
        $bank = $reconciliation->bank;
        $error = $bank->feed_enabled ? $this->feed->sync($bank)['error'] : null;

        return response()->json(['data' => ['added' => $this->feed->pullInto($reconciliation, $request->user()->id), 'error' => $error]]);
    }
}
