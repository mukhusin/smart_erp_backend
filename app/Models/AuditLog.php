<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Closure;
use Illuminate\Database\Eloquent\Model;

/** Who created, changed or deleted an audited record: old and new values, user, IP. Written by the Auditable trait. */
class AuditLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const ACTIONS = ['created' => 'Created', 'updated' => 'Changed', 'deleted' => 'Deleted'];

    public const TYPES = [
        'employee' => 'Employee',
        'employee-contract' => 'Contract',
        'employee-bank-account' => 'Bank account',
        'employee-fund' => 'Pension fund',
        'emp-supervisor' => 'Supervisor',
        'head-of-department' => 'Head of department',
        'employee-qualification' => 'Qualification',
        'employee-licence' => 'Licence',
        'employee-experience' => 'Experience',
        'employee-dependant' => 'Dependant',
        'emp-allowance' => 'Employee allowance',
        'department-allowance' => 'Department allowance',
        'other-deduction' => 'Deduction',
        'employee-loan' => 'Loan',
        'employee-monthly-advance' => 'Salary advance',
        'salary-adjustment' => 'Salary adjustment',
        'salary-scale' => 'Salary scale',
        'relief' => 'Relief',
        'heslb' => 'HESLB',
        'saccos-member' => 'SACCOS member',
        'payroll-sheet' => 'Payroll',
        'payroll-date-setting' => 'Payroll settings',
        'overtime-rate' => 'Overtime rate',
        'leave-balance-adjustment' => 'Leave balance adjustment',
        'leave-type' => 'Leave type',
        'hr-setting' => 'HR settings',
        'job-position' => 'Position',
        'department' => 'Department',
        'user' => 'User account',
        'workflow-template' => 'Approval workflow',
        'workflow-step' => 'Approval workflow step',
        'workflow-instance' => 'Approval request',
        'workflow-instance-step' => 'Approval decision',
    ];

    public const IGNORED = ['created_at', 'updated_at', 'password', 'remember_token', 'otp', 'otp_expiry', 'verification_code'];

    private static bool $paused = false;

    protected $fillable = ['company_id', 'auditable_type', 'auditable_id', 'action', 'old_values', 'new_values', 'employee_id', 'user_id', 'ip_address', 'user_agent', 'url'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    public static function paused(): bool
    {
        return self::$paused;
    }

    public static function withoutLogging(Closure $callback): mixed
    {
        $before = self::$paused;
        self::$paused = true;
        try {
            return $callback();
        } finally {
            self::$paused = $before;
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }

    /** @return list<array{field: string, label: string, old: mixed, new: mixed}> */
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        return collect(array_keys($old + $new))->map(fn ($field) => [
            'field' => $field,
            'label' => self::label($field),
            'old' => $old[$field] ?? null,
            'new' => $new[$field] ?? null,
        ])->values()->all();
    }

    public static function label(string $field): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', preg_replace('/_id$/', '', $field))));
    }
}
