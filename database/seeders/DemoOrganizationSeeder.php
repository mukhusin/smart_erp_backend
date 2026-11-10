<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\JobPosition;
use App\Services\CompanyContext;
use Illuminate\Database\Seeder;

class DemoOrganizationSeeder extends Seeder
{
    private const STRUCTURE = [
        'Human Resources' => ['sections' => ['Recruitment', 'Payroll Office'], 'positions' => ['HR Officer', 'Payroll Officer']],
        'Nursing' => ['sections' => ['Wards', 'Theatre', 'Emergency'], 'positions' => ['Registered Nurse', 'Enrolled Nurse', 'Nursing Officer']],
        'Medical' => ['sections' => ['Internal Medicine', 'Surgery', 'Paediatrics'], 'positions' => ['Medical Officer', 'Specialist', 'Intern Doctor']],
        'Laboratory' => ['sections' => ['Haematology', 'Microbiology'], 'positions' => ['Laboratory Technologist', 'Laboratory Assistant']],
        'Pharmacy' => ['sections' => [], 'positions' => ['Pharmacist', 'Pharmaceutical Technician']],
        'Finance' => ['sections' => ['Accounts', 'Billing'], 'positions' => ['Accountant', 'Cashier']],
        'ICT' => ['sections' => [], 'positions' => ['ICT Officer', 'Systems Administrator']],
    ];

    public function run(): void
    {
        $company = Company::where('slug', 'demo')->first();
        if (! $company) {
            return;
        }

        app(CompanyContext::class)->run($company, function () {
            foreach (self::STRUCTURE as $name => $parts) {
                $department = Department::firstOrCreate(['name' => $name, 'parent_id' => null], ['is_active' => true]);
                foreach ($parts['sections'] as $section) {
                    Department::firstOrCreate(['name' => $section, 'parent_id' => $department->id], ['is_active' => true]);
                }
                foreach ($parts['positions'] as $title) {
                    JobPosition::firstOrCreate(['title' => $title], ['department_id' => $department->id, 'is_active' => true]);
                }
            }
        });
    }
}
