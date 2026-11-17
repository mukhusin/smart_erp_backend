<?php

namespace Modules\Accounting\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\BankFeed;

class BankFeeds extends Command
{
    protected $signature = 'accounting:bank-feeds';

    protected $description = 'Pull new transactions from the banks whose feed is switched on.';

    public function handle(BankFeed $feed): int
    {
        foreach (Company::query()->get() as $company) {
            if (! $company->modules()->where('module_slug', 'accounting')->where('is_enabled', true)->exists()) {
                continue;
            }
            app(CompanyContext::class)->run($company, function () use ($feed) {
                foreach (CashBankAccount::where('is_active', true)->where('feed_enabled', true)->get() as $bank) {
                    $r = $feed->sync($bank);
                    $this->line("{$bank->name}: {$r['added']} new".($r['error'] ? " — {$r['error']}" : ''));
                }
            });
        }

        return self::SUCCESS;
    }
}
