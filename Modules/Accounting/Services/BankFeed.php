<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Accounting\Models\BankFeedTransaction;
use Modules\Accounting\Models\BankReconciliation;
use Modules\Accounting\Models\CashBankAccount;

/**
 * Reads bank transactions over REST, as configured on the bank account: url, params (values may use {from} and {to}),
 * optional token (bearer, or a named header), list_path to the list in the JSON answer, and the field of each item for
 * id, date, description, reference and either a signed amount or credit and debit amounts.
 */
class BankFeed
{
    public const FIELDS = ['url', 'token', 'token_header', 'params', 'list_path', 'id_field', 'date_field', 'description_field', 'reference_field', 'amount_field', 'credit_field', 'debit_field', 'days'];

    /** @return array{rows: list<array{external_id: string, date: string, description: string|null, reference: string|null, amount: float}>, error: string|null, raw: string|null} */
    public function fetch(CashBankAccount $bank, string $from, string $to): array
    {
        $c = $bank->feed_config ?? [];
        if (empty($c['url'])) {
            return ['rows' => [], 'error' => 'No address is set for the bank feed.', 'raw' => null];
        }
        $params = collect($c['params'] ?? [])->map(fn ($v) => str_replace(['{from}', '{to}'], [$from, $to], (string) $v))->all();
        try {
            $request = Http::timeout(60)->acceptJson();
            if (! empty($c['token'])) {
                $request = empty($c['token_header']) ? $request->withToken($c['token']) : $request->withHeaders([$c['token_header'] => $c['token']]);
            }
            $response = $request->get($c['url'], $params);
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => 'Could not reach the bank: '.Str::limit($e->getMessage(), 200), 'raw' => null];
        }
        $raw = Str::limit($response->body(), 20000, '');
        if (! $response->successful()) {
            return ['rows' => [], 'error' => "The bank answered with HTTP {$response->status()}.", 'raw' => $raw];
        }
        $list = data_get($response->json(), $c['list_path'] ?? '');
        if (! is_array($list)) {
            return ['rows' => [], 'error' => 'No list at "'.($c['list_path'] ?? '').'" in the answer.', 'raw' => $raw];
        }
        $rows = [];
        foreach (array_values($list) as $i => $item) {
            $date = $this->date(data_get($item, $c['date_field'] ?? 'date'));
            if (! $date) {
                continue;
            }
            $amount = ! empty($c['amount_field'])
                ? $this->number(data_get($item, $c['amount_field']))
                : $this->number(data_get($item, $c['credit_field'] ?? 'credit')) - $this->number(data_get($item, $c['debit_field'] ?? 'debit'));
            if (round($amount, 2) == 0) {
                continue;
            }
            $id = (string) (data_get($item, $c['id_field'] ?? 'id') ?? '');
            $description = mb_substr(trim((string) data_get($item, $c['description_field'] ?? 'description', '')), 0, 255) ?: null;
            $reference = mb_substr(trim((string) data_get($item, $c['reference_field'] ?? 'reference', '')), 0, 100) ?: null;
            $rows[] = ['external_id' => mb_substr($id !== '' ? $id : sha1($date.'|'.$amount.'|'.$description.'|'.$reference.'|'.$i), 0, 191), 'date' => $date,
                'description' => $description, 'reference' => $reference, 'amount' => round($amount, 2)];
        }

        return ['rows' => $rows, 'error' => null, 'raw' => $raw];
    }

    /** Pulls the last days of transactions and keeps the new ones. @return array{added: int, error: string|null} */
    public function sync(CashBankAccount $bank): array
    {
        $days = max(1, min(90, (int) (($bank->feed_config ?? [])['days'] ?? 7)));
        $result = $this->fetch($bank, today()->subDays($days)->toDateString(), today()->toDateString());
        $added = 0;
        foreach ($result['rows'] as $row) {
            $tx = BankFeedTransaction::firstOrCreate(['cash_bank_account_id' => $bank->id, 'external_id' => $row['external_id']], array_merge($row, ['company_id' => $bank->company_id]));
            $added += $tx->wasRecentlyCreated ? 1 : 0;
        }
        $bank->forceFill(['feed_checked_at' => now(), 'feed_error' => $result['error'] ? mb_substr($result['error'], 0, 500) : null])->save();

        return ['added' => $added, 'error' => $result['error']];
    }

    /** Copies feed transactions up to the statement date, not used before, into the reconciliation's statement lines. */
    public function pullInto(BankReconciliation $rec, int $userId): int
    {
        $count = 0;
        $txs = BankFeedTransaction::where('cash_bank_account_id', $rec->cash_bank_account_id)->whereNull('bank_statement_line_id')
            ->whereDate('date', '<=', $rec->statement_date)->orderBy('date')->orderBy('id')->get();
        foreach ($txs as $tx) {
            $line = $rec->statementLines()->create(['company_id' => $rec->company_id, 'cash_bank_account_id' => $rec->cash_bank_account_id, 'date' => $tx->date->toDateString(),
                'description' => $tx->description, 'reference' => $tx->reference, 'amount' => $tx->amount, 'created_by' => $userId]);
            $tx->update(['bank_statement_line_id' => $line->id]);
            $count++;
        }

        return $count;
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return (is_numeric($value) ? Carbon::createFromTimestamp((int) ($value > 9999999999 ? $value / 1000 : $value)) : Carbon::parse((string) $value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }
}
