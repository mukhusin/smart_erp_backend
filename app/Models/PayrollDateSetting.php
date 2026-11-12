<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Payroll settings (HRMS payroll date settings): the payroll period, statutory rates, PAYE bands and what payroll
 * includes. Rates are percentages; the employee fund is an amount.
 */
class PayrollDateSetting extends Model
{
    use BelongsToCompany;

    /** Tanzania PAYE (monthly): up to each amount, the rate on the part above the previous band. */
    public const TAX_BANDS = [
        ['up_to' => 270000, 'rate' => 0], ['up_to' => 520000, 'rate' => 8], ['up_to' => 760000, 'rate' => 20],
        ['up_to' => 1000000, 'rate' => 25], ['up_to' => null, 'rate' => 30],
    ];

    public const DEFAULTS = [
        'from_day' => 26, 'from_month' => 'last', 'to_day' => 25, 'to_month' => 'current',
        'wcf' => 0.5, 'sdl' => 3.5, 'heslb' => 15, 'paye' => 18, 'income_tax' => 30,
        'nhif_employee' => 3, 'nhif_employer' => 3, 'nssf_employee' => 10, 'nssf_employer' => 10, 'emergency_fund' => 5000,
        'use_overtime' => true, 'use_loan' => true, 'use_allowance' => true, 'use_attendance' => false, 'use_leave_deduction' => true,
        'use_deduction' => true, 'use_saccos' => true, 'use_relief' => true, 'is_active' => true,
    ];

    protected $fillable = [
        'company_id', 'from_day', 'from_month', 'to_day', 'to_month',
        'wcf', 'sdl', 'heslb', 'paye', 'income_tax', 'tax_bands',
        'nhif_employee', 'nhif_employer', 'nssf_employer', 'nssf_employee', 'emergency_fund',
        'use_overtime', 'use_loan', 'use_allowance', 'use_attendance',
        'use_leave_deduction', 'use_deduction', 'use_saccos', 'use_relief', 'is_active', 'updated_by',
    ];

    protected $casts = [
        'use_overtime' => 'boolean', 'use_loan' => 'boolean', 'use_allowance' => 'boolean', 'use_attendance' => 'boolean',
        'use_leave_deduction' => 'boolean', 'use_deduction' => 'boolean', 'use_saccos' => 'boolean', 'use_relief' => 'boolean', 'is_active' => 'boolean',
        'tax_bands' => 'array', 'from_day' => 'integer', 'to_day' => 'integer',
        'wcf' => 'float', 'sdl' => 'float', 'heslb' => 'float', 'income_tax' => 'float', 'emergency_fund' => 'float',
        'nhif_employee' => 'float', 'nhif_employer' => 'float', 'nssf_employee' => 'float', 'nssf_employer' => 'float',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? new static(self::DEFAULTS + ['tax_bands' => self::TAX_BANDS]);
    }

    public function bands(): array
    {
        return $this->tax_bands ?: self::TAX_BANDS;
    }

    /** The payroll period of a month, e.g. 26 Oct – 25 Nov for November (as HRMS). @return array{0: Carbon, 1: Carbon} */
    public function period(int $year, int $month): array
    {
        $base = Carbon::create($year, $month, 1)->startOfDay();
        $fromMonth = $this->from_month === 'last' ? $base->copy()->subMonthNoOverflow() : $base->copy();
        $toMonth = $this->to_month === 'next' ? $base->copy()->addMonthNoOverflow() : $base->copy();
        $from = $fromMonth->copy()->day(min((int) $this->from_day ?: 1, $fromMonth->daysInMonth));
        $to = $toMonth->copy()->day(min((int) $this->to_day ?: $toMonth->daysInMonth, $toMonth->daysInMonth));

        return [$from, $to];
    }

    public function updatedBy()
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }
}
