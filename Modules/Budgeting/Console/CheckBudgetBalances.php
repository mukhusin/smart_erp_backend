<?php

namespace Modules\Budgeting\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Budgeting\Models\BudgetEntry;
use Modules\Budgeting\Models\BudgetItem;

class CheckBudgetBalances extends Command
{
    protected $signature = 'budget:check-balances';

    protected $description = 'Compare budget balances with their entries and with the ledger, and report any mismatch';

    public function handle(CompanyContext $context): int
    {
        $problems = 0;
        foreach (Company::query()->pluck('id') as $companyId) {
            $context->run($companyId, function () use (&$problems, $companyId) {
                $approved = BudgetEntry::where('balance', 'approved')->groupBy('budget_item_id')->selectRaw('budget_item_id, SUM(amount) as t')->pluck('t', 'budget_item_id');
                $spent = BudgetEntry::where('balance', 'spent')->groupBy('budget_item_id')->selectRaw('budget_item_id, SUM(amount) as t')->pluck('t', 'budget_item_id');
                $ledger = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('budget_items as i', 'i.id', '=', 'l.budget_item_id')
                    ->join('accounts as a', 'a.id', '=', 'i.account_id')->where('e.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])
                    ->groupBy('l.budget_item_id', 'a.type')->selectRaw('l.budget_item_id, a.type, SUM(l.debit) as d, SUM(l.credit) as c')->get()
                    ->mapWithKeys(fn ($r) => [$r->budget_item_id => $r->type === 'revenue' ? $r->c - $r->d : $r->d - $r->c]);
                $items = BudgetItem::whereHas('budget', fn ($q) => $q->whereIn('status', ['approved', 'closed']))->get();
                foreach ($items as $item) {
                    foreach ([
                        'revised budget' => [(float) $item->revised_amount, (float) ($approved[$item->id] ?? 0)],
                        'spent vs ledger' => [(float) ($ledger[$item->id] ?? 0), (float) ($spent[$item->id] ?? 0)],
                    ] as $what => [$expected, $actual]) {
                        if (abs($expected - $actual) > 0.01) {
                            $problems++;
                            $message = "Company {$companyId}, budget item {$item->id} ({$item->label()}): {$what} ".number_format($expected, 2).' but entries add up to '.number_format($actual, 2);
                            $this->warn($message);
                            Log::warning('budget-balance-mismatch', ['message' => $message]);
                        }
                    }
                }
            });
        }
        $problems ? $this->error("{$problems} mismatch(es) found.") : $this->info('All budget balances match their entries and the ledger.');

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
