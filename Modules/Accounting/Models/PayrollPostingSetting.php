<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollPostingSetting extends Model
{
    use Auditable, BelongsToCompany;

    public const ACCOUNTS = [
        'salary' => 'Salaries and wages (expense)', 'employer' => 'Employer contributions (expense)', 'net_pay' => 'Net salaries payable',
        'paye' => 'PAYE payable', 'nssf' => 'NSSF payable (employee + employer)', 'nhif' => 'NHIF payable (employee + employer)', 'heslb' => 'HESLB payable',
        'wcf' => 'WCF payable', 'sdl' => 'SDL payable', 'loans' => 'Staff loans payable', 'other' => 'Other deductions payable',
    ];

    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean'];

    public static function current(): self
    {
        return static::firstOrNew([], ['enabled' => false]);
    }

    public function account(string $key): ?int
    {
        return $this->{"{$key}_account_id"} ?: null;
    }
}
