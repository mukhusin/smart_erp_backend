<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Database\Seeder;

class DemoSimulationSeeder extends Seeder
{
    private const PARTS = [
        'HR: staff, heads, leave, attendance, overtime, loans' => [Demo\DemoHrSeeder::class, 'run'],
        'Accounting: chart, fiscal years, banks, opening balances' => [Demo\DemoAccountingSeeder::class, 'run'],
        'Payroll: three approved months posted to the ledger' => [Demo\DemoHrSeeder::class, 'payroll'],
        'Planning and budget 2026/27' => [Demo\DemoPlanningSeeder::class, 'run'],
        'Stores, suppliers and purchases' => [Demo\DemoSupplySeeder::class, 'run'],
        'Payments, imprest, petty cash, receivables' => [Demo\DemoFinanceSeeder::class, 'run'],
        'Assets and fleet' => [Demo\DemoAssetsFleetSeeder::class, 'run'],
        'Appraisals, tasks, audit' => [Demo\DemoPeopleSeeder::class, 'run'],
    ];

    public function run(): void
    {
        config(['queue.default' => 'sync', 'mail.default' => 'log']);
        $company = Company::where('slug', 'demo')->firstOrFail();
        $demo = new Demo\Demo($company);
        $only = array_filter(explode(',', (string) env('DEMO_ONLY')));

        app(CompanyContext::class)->run($company, function () use ($demo, $company, $only) {
            setPermissionsTeamId($company->id);
            foreach (self::PARTS as $label => [$class, $method]) {
                if ($only && ! in_array(class_basename($class), $only, true)) {
                    continue;
                }
                $started = microtime(true);
                auth()->setUser($demo->user('hr'));
                (new $class($demo))->$method();
                auth()->forgetUser();
                $this->command->info(sprintf('%s: done in %.1fs', $label, microtime(true) - $started));
            }
        });

        foreach (array_slice($demo->notes, 0, 80) as $n) {
            $this->command->warn($n);
        }
        if (count($demo->notes) > 80) {
            $this->command->warn((count($demo->notes) - 80).' more notes.');
        }
    }
}
