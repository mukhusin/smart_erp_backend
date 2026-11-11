<?php

namespace Modules\Hr\Support;

use App\Models\EmployeeFund;
use App\Models\Heslb;
use App\Models\Nhif;
use App\Models\Nic;
use App\Models\Nssf;
use App\Models\Tughe;

/**
 * Statutory memberships and fixed deductions, with the HRMS rules:
 * NSSF 20% of basic shared (employee rate chosen, employer pays the rest), NHIF 6% shared the same way,
 * HESLB and TUGHE a percentage of basic paid by the employee, NIC and the employee fund a fixed monthly amount.
 * NSSF and NHIF numbers are unique and copied to the employee record. One membership per scheme and employee.
 */
final class DeductionSchemes
{
    /** @var array<string, array{label: string, model: class-string, type: string, default: float, total: ?float, number: ?string, number_label: ?string, unique: bool, employee_field: ?string, description: string}> */
    public const SCHEMES = [
        'nssf' => ['label' => 'NSSF', 'model' => Nssf::class, 'type' => 'percent', 'default' => 10, 'total' => 20, 'number' => 'required',
            'number_label' => 'Membership no.', 'unique' => true, 'employee_field' => 'nssf_no', 'description' => 'Social security pension'],
        'nhif' => ['label' => 'NHIF', 'model' => Nhif::class, 'type' => 'percent', 'default' => 3, 'total' => 6, 'number' => 'required',
            'number_label' => 'Membership no.', 'unique' => true, 'employee_field' => 'nhif_no', 'description' => 'National health insurance'],
        'heslb' => ['label' => 'HESLB', 'model' => Heslb::class, 'type' => 'percent', 'default' => 15, 'total' => null, 'number' => 'optional',
            'number_label' => 'Loan / form 4 index no.', 'unique' => false, 'employee_field' => null, 'description' => 'Student loan repayment'],
        'tughe' => ['label' => 'TUGHE', 'model' => Tughe::class, 'type' => 'percent', 'default' => 2, 'total' => null, 'number' => 'optional',
            'number_label' => 'Member no.', 'unique' => false, 'employee_field' => null, 'description' => 'Trade union dues'],
        'nic' => ['label' => 'NIC', 'model' => Nic::class, 'type' => 'amount', 'default' => 0, 'total' => null, 'number' => 'optional',
            'number_label' => 'Policy no.', 'unique' => false, 'employee_field' => null, 'description' => 'Insurance premium (fixed monthly amount)'],
        'fund' => ['label' => 'Employee fund', 'model' => EmployeeFund::class, 'type' => 'amount', 'default' => 0, 'total' => null, 'number' => null,
            'number_label' => null, 'unique' => false, 'employee_field' => null, 'description' => 'Emergency / welfare fund (fixed monthly amount)'],
    ];

    public static function get(string $scheme): array
    {
        abort_unless(isset(self::SCHEMES[$scheme]), 404, 'Unknown deduction.');

        return self::SCHEMES[$scheme];
    }

    /** Field holding the employee's rate or amount. */
    public static function valueField(string $scheme): string
    {
        return $scheme === 'fund' ? 'amount' : 'emp_rate';
    }
}
