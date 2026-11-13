<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Import\HrmsImporter;
use Modules\Hr\Import\LegacyIds;
use Modules\Hr\Support\LeaveBalances;

class ImportHrmsCommand extends Command
{
    protected $signature = 'erp:import-hrms
        {company : Slug or id of the ERP company to load into}
        {--only= : Comma-separated steps (default: all)}
        {--files= : HRMS storage/app/public folder, to copy photos and documents}
        {--dry-run : Run everything, report, then roll back}';

    protected $description = 'Copy HRMS data (connection "hrms") into an ERP company; safe to run again';

    public function handle(CompanyContext $context): int
    {
        $company = Company::where('slug', $this->argument('company'))->orWhere('id', $this->argument('company'))->first();
        if (! $company) {
            $this->error('Company not found.');

            return self::FAILURE;
        }

        $steps = $this->option('only') ? array_map('trim', explode(',', $this->option('only'))) : HrmsImporter::STEPS;
        if ($unknown = array_diff($steps, HrmsImporter::STEPS)) {
            $this->error('Unknown steps: '.implode(', ', $unknown).'. Steps: '.implode(', ', HrmsImporter::STEPS));

            return self::FAILURE;
        }

        try {
            DB::connection('hrms')->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the HRMS database (HRMS_DB_* in .env): '.$e->getMessage());

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? '[dry run] ' : '')."Importing HRMS into {$company->name}: ".implode(', ', $steps));
        $started = microtime(true);

        DB::beginTransaction();
        try {
            $report = $context->run($company, fn () => (new HrmsImporter($company, new LegacyIds($company->id), $this->option('files'), $dry))->run($steps));
            $checks = $context->run($company, fn () => $this->checks($company->id, $steps));
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import failed and was rolled back: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->table(['HRMS table', 'Read', 'Inserted', 'Updated', 'Skipped'], collect($report)->map(fn ($s, $t) => [$t, $s['read'], $s['inserted'], $s['updated'], $s['skipped']])->values()->all());
        foreach ($report as $table => $s) {
            foreach ($s['notes'] as $note) {
                $this->line("  <comment>{$table}</comment>: {$note}");
            }
        }
        $this->table(['Check', 'HRMS', 'ERP', 'Result'], array_map(fn ($c) => [$c[0], $c[1], $c[2], $c[1] == $c[2] ? 'OK' : 'DIFFERENT'], $checks));
        $this->info(sprintf('%s in %.1fs.', $dry ? 'Dry run rolled back' : 'Done', microtime(true) - $started));

        return collect($checks)->contains(fn ($c) => $c[1] != $c[2]) ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array{0: string, 1: int|float, 2: int|float}> */
    private function checks(int $companyId, array $steps): array
    {
        $src = DB::connection('hrms');
        $ids = new LegacyIds($companyId);
        $employees = array_values($ids->all('employees'));
        $checks = [];

        if ($employees && in_array('employees', $steps, true)) {
            $checks[] = ['Employees', $src->table('employees')->count(), count($employees)];
        }
        if ($employees && in_array('contracts', $steps, true)) {
            $checks[] = ['Active contracts', $src->table('employee_contracts')->where('isActive', 1)->whereNull('deleted_at')->count(),
                DB::table('employee_contracts')->whereIn('employee_id', $employees)->where('is_active', true)->whereNull('deleted_at')->count()];
            $checks[] = ['Basic salary of active contracts', round((float) $src->table('employee_contracts')->where('isActive', 1)->whereNull('deleted_at')->sum('basic_salary'), 2),
                round((float) DB::table('employee_contracts')->whereIn('employee_id', $employees)->where('is_active', true)->whereNull('deleted_at')->sum('basic_salary'), 2)];
        }
        if (in_array('leave', $steps, true) && $ids->all('emp_leaves')) {
            $year = (string) now()->year;
            $taken = ['reported', 'on leave', 'approved'];
            $annual = LeaveType::where('kind', 'annual')->first();
            $checks[] = ['Leave records (non-annual)', $src->table('emp_leaves')->count(), count($ids->all('emp_leaves'))];
            $checks[] = ["Annual leave days taken in {$year}", (float) $src->table('annual_leave_rosters')->where('leave_year', $year)->whereIn('status', $taken)->sum('days'),
                (float) DB::table('emp_leaves')->where('leave_type_id', $annual?->id)->where('leave_year', $year)->whereIn('employee_id', $employees)
                    ->whereIn('status', ['reported', 'on_leave', 'approved'])->whereIn('id', array_values($ids->all('annual_leave_rosters:leave')))->sum('days')];
            $balances = app(LeaveBalances::class);
            [$hrms, $erp] = [0.0, 0.0];
            foreach ($src->table('annual_leave_rosters')->where('leave_year', $year)->orderBy('id')->get()->keyBy('employee_id') as $hrmsEmployee => $row) {
                $employee = Employee::find($ids->get('employees', $hrmsEmployee));
                if (! $annual || ! $employee || $employee->employment_status === 'terminated') {
                    continue;
                }
                $balance = $balances->for($employee, $annual);
                if (! $balance['eligible']) {
                    continue;
                }
                $hrms += (float) $row->actual_balance;
                $erp += $balance['available'];
            }
            $checks[] = ['Annual leave available now (staff with a '.$year.' entry)', round($hrms, 1), round($erp, 1)];
        }
        if (in_array('users', $steps, true) && ($users = $ids->all('users'))) {
            $checks[] = ['Users', $src->table('users')->count(), count($users)];
        }

        return $checks;
    }
}
