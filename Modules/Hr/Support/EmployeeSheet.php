<?php

namespace Modules\Hr\Support;

use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Hr\Actions\RegisterEmployee;
use Modules\Hr\Http\Requests\StoreEmployeeRequest;
use Modules\Hr\Support\EmployeeOptions as O;

/** Employee CSV export (chosen columns) and import (validate all rows, then create all or nothing). */
class EmployeeSheet
{
    public const EXPORT_COLUMNS = [
        'employee_no' => 'Employee number',
        'name' => 'Full name',
        'emp_title' => 'Title',
        'gender' => 'Gender',
        'date_of_birth' => 'Date of birth',
        'email' => 'Email',
        'phone_number' => 'Phone number',
        'branch' => 'Branch',
        'department' => 'Department',
        'section' => 'Section',
        'position' => 'Position',
        'emp_type' => 'Employee type',
        'emp_category' => 'Category',
        'employment_date' => 'Employment date',
        'employment_status' => 'Employment status',
        'contract_status' => 'Contract status',
        'contract_end' => 'Contract end',
        'basic_salary' => 'Basic salary',
        'tin' => 'TIN',
        'id_number' => 'ID number',
        'nssf_no' => 'NSSF number',
        'nhif_no' => 'NHIF number',
        'has_account' => 'Has account',
    ];

    public const DEFAULT_EXPORT = ['employee_no', 'name', 'emp_title', 'email', 'phone_number', 'position', 'department', 'employment_date', 'gender', 'emp_type', 'contract_status'];

    public const SALARY_COLUMNS = ['basic_salary'];

    public const IMPORT_COLUMNS = [
        'employee_no', 'emp_title', 'fname', 'mname', 'lname', 'gender', 'date_of_birth', 'place_of_birth', 'nationality',
        'marital_status', 'id_type', 'id_number', 'tin', 'phone_number', 'email', 'home_address', 'branch', 'department',
        'section', 'position', 'emp_type', 'emp_category', 'employment_date', 'employment_status', 'probation_start',
        'probation_period', 'place_of_recruitment', 'nssf_no', 'nhif_no', 'wcf_no', 'create_account',
    ];

    private const REQUIRED = ['fname', 'lname', 'gender', 'branch', 'department', 'position', 'emp_type', 'employment_date', 'employment_status'];

    private const DATES = ['date_of_birth', 'employment_date', 'probation_start'];

    private const CHOICES = [
        'gender' => O::GENDERS, 'marital_status' => O::MARITAL_STATUSES, 'id_type' => O::ID_TYPES,
        'emp_type' => O::EMPLOYMENT_TYPES, 'emp_category' => O::CATEGORIES,
        'employment_status' => ['probation' => 'On probation', 'confirmed' => 'Confirmed'],
    ];

    public function __construct(private readonly RegisterEmployee $register) {}

    /** @return list<string> */
    public function columnsFor(?string $requested, User $user): array
    {
        $allowed = array_keys(self::EXPORT_COLUMNS);
        if (! $this->seesSalary($user)) {
            $allowed = array_diff($allowed, self::SALARY_COLUMNS);
        }
        $columns = array_values(array_intersect(array_filter(array_map('trim', explode(',', (string) $requested))), $allowed));

        return $columns ?: array_values(array_intersect(self::DEFAULT_EXPORT, $allowed));
    }

    public function seesSalary(User $user): bool
    {
        return $user->can('hr.contracts.manage') || $user->can('payroll.view');
    }

    /** @param  list<string>  $columns */
    public function exportCsv(Collection $employees, array $columns): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($c) => self::EXPORT_COLUMNS[$c], $columns));
        foreach ($employees as $employee) {
            fputcsv($out, array_map(fn ($c) => $this->value($employee, $c), $columns));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function value(Employee $e, string $column): string
    {
        $date = fn ($d) => $d ? Carbon::parse($d)->toDateString() : '';

        return (string) match ($column) {
            'name' => trim(preg_replace('/\s+/', ' ', "{$e->fname} {$e->mname} {$e->lname}")),
            'date_of_birth', 'employment_date' => $date($e->{$column}),
            'branch' => $e->branch?->name,
            'department' => $e->department?->name,
            'section' => $e->section?->name,
            'position' => $e->jobPosition?->title,
            'emp_type' => O::EMPLOYMENT_TYPES[$e->emp_type] ?? $e->emp_type,
            'employment_status' => O::EMPLOYMENT_STATUSES[$e->employment_status] ?? $e->employment_status,
            'contract_status' => $e->activeContract ? O::contractStatusLabel($e->activeContract->status) : 'No active contract',
            'contract_end' => $date($e->activeContract?->end_date),
            'basic_salary' => $e->activeContract?->basic_salary,
            'has_account' => $e->user_id ? 'Yes' : 'No',
            default => $e->{$column},
        };
    }

    public function templateCsv(): string
    {
        $example = [
            '', 'Mr', 'Juma', 'Ali', 'Hassan', 'Male', '1990-05-14', 'Dar es Salaam', 'Tanzanian', 'married', 'nida', '19900514-12345-00001-12',
            '123-456-789', '0712000000', 'juma.hassan@example.com', 'Kinondoni', 'Head Office', 'Finance', '', 'Accountant', 'full_time',
            'Primary', '2026-11-01', 'probation', '2026-11-01', '6', 'Dar es Salaam', '', '', '', 'no',
        ];

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::IMPORT_COLUMNS);
        fputcsv($out, $example);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @return array{rows: list<array{line: int, employee_no: ?string, name: string, errors: list<string>}>, valid: int, invalid: int, created: int, invites_sent: int}
     */
    public function import(UploadedFile $file, bool $commit): array
    {
        $parsed = $this->parse($file);
        $lookups = $this->lookups();
        $seen = ['employee_no' => [], 'email' => []];
        $rows = [];

        foreach ($parsed as $line => $raw) {
            [$data, $errors] = $this->resolve($raw, $lookups);

            foreach (['employee_no', 'email'] as $key) {
                $value = strtolower((string) ($data[$key] ?? ''));
                if ($value !== '' && isset($seen[$key][$value])) {
                    $errors[] = "Same {$this->label($key)} as line {$seen[$key][$value]}.";
                } elseif ($value !== '') {
                    $seen[$key][$value] = $line;
                }
            }

            if (! $errors) {
                $errors = $this->validate($data);
            }

            $rows[] = ['line' => $line, 'employee_no' => $data['employee_no'] ?? null, 'name' => trim(($raw['fname'] ?? '').' '.($raw['lname'] ?? '')), 'errors' => $errors, 'data' => $data];
        }

        $invalid = count(array_filter($rows, fn ($r) => $r['errors']));
        $created = 0;
        $invites = 0;

        if ($commit && ! $invalid && $rows) {
            DB::transaction(function () use (&$rows, &$created, &$invites) {
                foreach ($rows as &$row) {
                    $data = $row['data'];
                    $data['employee_no'] ??= $this->register->nextNumber(null, $data['department_id'], $data['emp_type']);
                    $result = $this->register->handle($data);
                    $row['employee_no'] = $result['employee']->employee_no;
                    $created++;
                    $invites += $result['invite_sent'] ? 1 : 0;
                }
            });
        }

        return [
            'rows' => array_map(fn ($r) => collect($r)->except('data')->all(), $rows),
            'valid' => count($rows) - $invalid,
            'invalid' => $invalid,
            'created' => $created,
            'invites_sent' => $invites,
        ];
    }

    /** @return array<int, array<string, string>> keyed by file line */
    private function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($h) => str_replace(' ', '_', strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)))), fgetcsv($handle) ?: []);
        $missing = array_diff(self::REQUIRED, $header);
        if ($missing) {
            fclose($handle);
            abort(response()->json(['message' => 'Missing columns: '.implode(', ', $missing).'. Download the template.'], 422));
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if (! array_filter($values, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $rows[$line] = array_map(fn ($v) => trim((string) $v), array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), '')));
            if (count($rows) > 2000) {
                fclose($handle);
                abort(response()->json(['message' => 'At most 2000 employees per file.'], 422));
            }
        }
        fclose($handle);

        return $rows;
    }

    private function lookups(): array
    {
        $key = fn (?string $v) => strtolower(trim((string) $v));

        return [
            'branches' => CompanyBranch::query()->where('is_active', true)->get(['id', 'name'])->keyBy(fn ($b) => $key($b->name)),
            'departments' => Department::query()->whereNull('parent_id')->get(['id', 'name', 'company_branch_id']),
            'sections' => Department::query()->whereNotNull('parent_id')->get(['id', 'name', 'parent_id']),
            'positions' => JobPosition::query()->get(['id', 'title', 'department_id']),
        ];
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function resolve(array $raw, array $lookups): array
    {
        $errors = [];
        $data = [];
        $same = fn (?string $a, ?string $b) => strtolower(trim((string) $a)) === strtolower(trim((string) $b));

        foreach (['employee_no', 'emp_title', 'fname', 'mname', 'lname', 'place_of_birth', 'nationality', 'id_number', 'tin', 'phone_number', 'email', 'home_address', 'place_of_recruitment', 'nssf_no', 'nhif_no', 'wcf_no', 'probation_period'] as $field) {
            if (($raw[$field] ?? '') !== '') {
                $data[$field] = $field === 'email' ? strtolower($raw[$field]) : $raw[$field];
            }
        }

        foreach (self::CHOICES as $field => $options) {
            $value = $raw[$field] ?? '';
            if ($value === '') {
                continue;
            }
            $match = collect($options)->search(fn ($label, $k) => $same($k, $value) || $same($label, $value) || $same(str_replace('_', ' ', $k), $value));
            $match === false ? $errors[] = "{$this->label($field)} \"{$value}\" is not one of: ".implode(', ', array_keys($options)).'.' : $data[$field] = $match;
        }

        foreach (self::DATES as $field) {
            if (($raw[$field] ?? '') === '') {
                continue;
            }
            $date = $this->date($raw[$field]);
            $date ? $data[$field] = $date : $errors[] = "{$this->label($field)} \"{$raw[$field]}\" is not a date (use YYYY-MM-DD or DD/MM/YYYY).";
        }

        $branch = $lookups['branches'][strtolower($raw['branch'] ?? '')] ?? null;
        $branch ? $data['company_branch_id'] = $branch->id : $errors[] = "Branch \"{$raw['branch']}\" not found.";

        $department = $lookups['departments']->first(fn ($d) => $same($d->name, $raw['department'] ?? '') && (! $d->company_branch_id || $d->company_branch_id === $branch?->id));
        $department ? $data['department_id'] = $department->id : $errors[] = "Department \"{$raw['department']}\" not found in this branch.";

        if (($raw['section'] ?? '') !== '') {
            $section = $lookups['sections']->first(fn ($s) => $same($s->name, $raw['section']) && $s->parent_id === $department?->id);
            $section ? $data['section_id'] = $section->id : $errors[] = "Section \"{$raw['section']}\" not found in this department.";
        }

        $position = $lookups['positions']->first(fn ($p) => $same($p->title, $raw['position'] ?? '') && $p->department_id === $department?->id)
            ?? $lookups['positions']->first(fn ($p) => $same($p->title, $raw['position'] ?? '') && ! $p->department_id);
        $position ? $data['job_position_id'] = $position->id : $errors[] = "Position \"{$raw['position']}\" not found for this department.";

        $wantsAccount = in_array(strtolower($raw['create_account'] ?? ''), ['yes', 'y', '1', 'true'], true);
        if (($data['employment_status'] ?? null) !== 'probation') {
            unset($data['probation_start'], $data['probation_period']);
        }

        return [$data + ['create_account' => $wantsAccount], $errors];
    }

    /** @return list<string> */
    private function validate(array &$data): array
    {
        $rules = StoreEmployeeRequest::create('/', 'POST', $data)->rules();
        if (! isset($data['employee_no'])) {
            unset($rules['employee_no']);
        }
        unset($rules['username']);

        $validator = Validator::make($data, $rules, (new StoreEmployeeRequest)->messages());
        $errors = $validator->errors()->all();

        $account = $data['create_account'] ? ['username' => null] : null;
        unset($data['create_account']);
        $data['account'] = $account;

        return $errors;
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'Y-n-j', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace(['_', 'emp '], [' ', 'employee '], $field));
    }
}
