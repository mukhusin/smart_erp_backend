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

        $this->table(['HRMS table', 'Read', 'Inserted', 'Updated', 'Unchanged', 'Skipped'], collect($report)->map(fn ($s, $t) => [$t, $s['read'], $s['inserted'], $s['updated'], $s['unchanged'] ?? 0, $s['skipped']])->values()->all());
        foreach ($report as $table => $s) {
            foreach ($s['notes'] as $note) {
                $this->line("  <comment>{$table}</comment>: {$note}");
            }
        }
        $this->table(['Check', 'HRMS', 'ERP', 'Result'], array_map(fn ($c) => [$c[0], $c[1], $c[2], $this->same($c) ? 'OK' : 'DIFFERENT'], $checks));
        $this->info(sprintf('%s in %.1fs.', $dry ? 'Dry run rolled back' : 'Done', microtime(true) - $started));

        return collect($checks)->contains(fn ($c) => ! $this->same($c)) ? self::FAILURE : self::SUCCESS;
    }

    /** A check passes when both sides match, or differ by no more than its rounding allowance (4th value). */
    private function same(array $check): bool
    {
        return abs((float) $check[1] - (float) $check[2]) <= ($check[3] ?? 0);
    }

    /** @return list<array{0: string, 1: int|float, 2: int|float, 3?: float}> */
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
        if (in_array('payroll', $steps, true) && ($sheets = $ids->all('payroll_sheets'))) {
            $checks[] = ['Payslips', $src->table('payroll_sheet_details')->whereNull('deleted_at')->count(),
                DB::table('payroll_sheet_details')->whereIn('payroll_sheet_id', array_values($sheets))->whereNull('deleted_at')->count()];
            foreach (['gross_salary' => 'Gross pay, all payrolls', 'net_salary' => 'Net pay, all payrolls', 'paye' => 'PAYE, all payrolls'] as $column => $label) {
                $checks[] = [$label.' (rounded to cents per payslip)', round((float) $src->table('payroll_sheet_details')->whereNull('deleted_at')->sum(strtoupper($column) === 'PAYE' ? 'PAYE' : $column), 2),
                    round((float) DB::table('payroll_sheet_details')->whereIn('payroll_sheet_id', array_values($sheets))->whereNull('deleted_at')->sum($column), 2),
                    round(0.005 * $src->table('payroll_sheet_details')->whereNull('deleted_at')->count(), 2)];
            }
        }
        if (in_array('transactions', $steps, true) && $ids->all('employee_loans')) {
            $checks[] = ['Loan balance remaining', round((float) $src->table('employee_loans')->whereNull('deleted_at')->sum('remain'), 2),
                round((float) DB::table('employee_loans')->whereIn('id', array_values($ids->all('employee_loans')))->whereNull('deleted_at')->sum('remain'), 2)];
            $checks[] = ['Daily allowance amount', round((float) $src->table('daily_allowances')->whereNull('deleted_at')->sum('amount'), 2),
                round((float) DB::table('daily_allowances')->where('company_id', $companyId)->whereIn('id', DB::table('legacy_ids')->select('target_id')->where('company_id', $companyId)->where('source', 'hrms')->where('table_name', 'daily_allowances'))->whereNull('deleted_at')->sum('amount'), 2)];
        }
        if (in_array('attendance', $steps, true)) {
            $legacy = fn (string $table) => DB::table('legacy_ids')->select('target_id')->where('company_id', $companyId)->where('source', 'hrms')->where('table_name', $table);
            $latest = $src->table('attendance_transas')->selectRaw('max(id)')->groupBy('employee_id')->groupByRaw('date(transaction_date)');
            $checks[] = ['Attendance days (one per employee per day)', $src->table('attendance_transas')->whereIn('id', $latest)->count(),
                DB::table('attendance_transas')->whereIn('id', $legacy('attendance_transas'))->count()];
            $checks[] = ['Attendance lost amount', round((float) $src->table('attendance_transas')->whereIn('id', $latest)->sum('lost_amount'), 2),
                round((float) DB::table('attendance_transas')->whereIn('id', $legacy('attendance_transas'))->sum('lost_amount'), 2), 1.0];
        }
        if (in_array('users', $steps, true) && ($users = $ids->all('users'))) {
            $checks[] = ['Users', $src->table('users')->count(), count($users)];
        }

        return $checks;
    }
}
