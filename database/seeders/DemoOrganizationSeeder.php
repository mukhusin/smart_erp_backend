<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Bank;
use App\Models\EmployeeNumberFormat;
use App\Models\HrSetting;
use App\Models\JobPosition;
use App\Models\NatureOfContract;
use App\Models\ProfessionalLicence;
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

    private const CONTRACT_TYPES = [
        ['Permanent and pensionable', 'PNP', 'full_time', true, true],
        ['Specific time contract', 'STC', 'full_time', true, true],
        ['Part time contract', 'PTC', 'part_time', false, false],
        ['Internship', 'INT', 'internship', false, false],
        ['Volunteer', 'VOL', 'volunteer', false, false],
    ];

    private const NUMBER_FORMATS = [
        'full_time' => ['Staff (ST)', [['type' => 'text', 'value' => 'ST'], ['type' => 'sequence', 'length' => 0, 'start_at' => 1]], true],
        'part_time' => ['Part time (PT)', [['type' => 'text', 'value' => 'PT'], ['type' => 'sequence', 'length' => 0, 'start_at' => 1]], false],
        'volunteer' => ['Volunteer (VOL)', [['type' => 'text', 'value' => 'VOL'], ['type' => 'sequence', 'length' => 0, 'start_at' => 1]], false],
        'internship' => ['Interns', [['type' => 'text', 'value' => 'INT/DH/'], ['type' => 'department', 'value' => 'GEN'], ['type' => 'text', 'value' => ':'],
            ['type' => 'sequence', 'length' => 4, 'start_at' => 1], ['type' => 'text', 'value' => '/'], ['type' => 'year', 'length' => 2]], false],
    ];

    private const BANKS = [
        ['CRDB Bank', 'CRDB'], ['NMB Bank', 'NMB'], ['NBC Bank', 'NBC'], ['Stanbic Bank', 'STANBIC'],
        ['Exim Bank', 'EXIM'], ['Azania Bank', 'AZANIA'], ['Equity Bank', 'EQUITY'],
    ];

    private const LICENCES = [
        ['Medical practising licence', 'MCT', 'Medical Council of Tanganyika'],
        ['Nursing and midwifery licence', 'TNMC', 'Tanzania Nursing and Midwifery Council'],
        ['Pharmacy practising licence', 'PC', 'Pharmacy Council'],
        ['Laboratory practitioner licence', 'HLPC', 'Health Laboratory Practitioners Council'],
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
            $formats = [];
            foreach (self::NUMBER_FORMATS as $key => [$name, $segments, $default]) {
                $format = EmployeeNumberFormat::firstOrCreate(['name' => $name], ['separator' => '', 'is_default' => $default]);
                if ($format->segments()->doesntExist()) {
                    foreach ($segments as $i => $segment) {
                        $format->segments()->create($segment + ['position' => $i + 1]);
                    }
                }
                $formats[$key] = $format->id;
            }
            foreach (self::CONTRACT_TYPES as [$name, $code, $type, $leave, $overtime]) {
                $contractType = NatureOfContract::firstOrCreate(['code' => $code], [
                    'contract_name' => $name, 'employment_type' => $type, 'has_annual_leave' => $leave,
                    'has_payroll' => true, 'has_overtime' => $overtime, 'is_active' => true,
                ]);
                $contractType->employee_number_format_id ??= $formats[$type] ?? null;
                $contractType->save();
            }
            foreach (self::BANKS as [$name, $code]) {
                Bank::firstOrCreate(['name' => $name], ['code' => $code, 'is_active' => true]);
            }
            HrSetting::firstOrCreate([], ['renumber_on_contract_change' => true]);
            foreach (self::LICENCES as [$name, $short, $provider]) {
                ProfessionalLicence::firstOrCreate(['name' => $name], ['short_name' => $short, 'provider_name' => $provider, 'is_active' => true]);
            }
        });
    }
}
