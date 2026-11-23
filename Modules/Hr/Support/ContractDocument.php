<?php

namespace Modules\Hr\Support;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use Illuminate\Http\Response;
use Modules\Core\Support\PdfDocument;
use Illuminate\Support\Carbon;
use Modules\Core\Support\RichText;

/** The contract of employment printed from the contract type's template, with {placeholders} filled from the contract. */
class ContractDocument
{
    public const PLACEHOLDERS = [
        'company_name' => 'Company name', 'company_address' => 'Company address', 'employee_name' => 'Full name with title', 'employee_no' => 'Employee number',
        'position' => 'Position', 'department' => 'Department', 'section' => 'Section', 'branch' => 'Branch / work station', 'supervisor' => 'Supervisor',
        'contract_type' => 'Contract type', 'contract_no' => 'Contract number', 'start_date' => 'Start date', 'end_date' => 'End date',
        'period' => 'Period, e.g. 12 months', 'probation_months' => 'Probation in months', 'basic_salary' => 'Basic salary a month',
        'date_of_birth' => 'Date of birth', 'nationality' => 'Nationality', 'id_number' => 'ID number', 'phone' => 'Phone', 'email' => 'Email',
        'home_address' => 'Home address', 'today' => "Today's date",
    ];

    public function template(EmployeeContract $contract): string
    {
        return $contract->natureOfContract?->template_content ?: self::defaultTemplate();
    }

    public function render(EmployeeContract $contract): string
    {
        $values = $this->values($contract);

        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $values) ? e($values[$m[1]] ?: '……………') : $m[0], $this->template($contract));
    }

    /** @return array<string, string|null> */
    public function values(EmployeeContract $contract): array
    {
        $contract->loadMissing('natureOfContract', 'employee.company', 'employee.department', 'employee.section', 'employee.branch', 'employee.jobPosition');
        /** @var Employee $e */
        $e = $contract->employee;
        $company = $e->company;
        $date = fn ($d) => $d ? Carbon::parse($d)->format('j F Y') : null;
        $supervisor = $e->supervisors()->latest('id')->first();
        $probation = $e->probation_start && $e->probation_end ? (int) round(Carbon::parse($e->probation_start)->diffInMonths(Carbon::parse($e->probation_end))) : null;

        return [
            'company_name' => $company?->name,
            'company_address' => collect([$company?->address, $company?->city, $company?->country])->filter()->implode(', '),
            'employee_name' => trim(preg_replace('/\s+/', ' ', "{$e->emp_title} {$e->fname} {$e->mname} {$e->lname}")),
            'employee_no' => $e->employee_no,
            'position' => $e->jobPosition?->title,
            'department' => $e->department?->name,
            'section' => $e->section?->name,
            'branch' => $e->branch?->name,
            'supervisor' => $supervisor ? Employee::withoutGlobalScopes()->find($supervisor->supervisor_id)?->full_name : null,
            'contract_type' => $contract->natureOfContract?->contract_name,
            'contract_no' => $contract->contract_no,
            'start_date' => $date($contract->start_date),
            'end_date' => $date($contract->end_date),
            'period' => $contract->period ? $contract->period.' months' : null,
            'probation_months' => $probation ? (string) $probation : null,
            'basic_salary' => $contract->basic_salary !== null ? ($company?->currency ?? 'TZS').' '.number_format((float) $contract->basic_salary, 2) : null,
            'date_of_birth' => $date($e->date_of_birth),
            'nationality' => $e->nationality,
            'id_number' => $e->id_number,
            'phone' => $e->phone_number,
            'email' => $e->email,
            'home_address' => $e->home_address,
            'today' => now()->format('j F Y'),
        ];
    }

    public function pdf(User $user, EmployeeContract $contract): Response
    {
        $values = $this->values($contract);

        $e = $contract->employee;
        $dependants = $e->dependants()->get();
        $kin = $dependants->firstWhere('is_next_of_kin', true);
        $date = fn ($d) => $d ? Carbon::parse($d)->format('j F Y') : null;

        return app(PdfDocument::class)->view($user, 'pdf.contract', 'Contract of employment', [
            'values' => $values, 'body' => $this->render($contract), 'jobDescription' => $this->jobDescription($e->jobPosition?->description),
            'personal' => [
                'Department' => trim(($e->department?->name ?? '').($e->section ? ' / '.$e->section->name : '')), 'Surname' => $e->lname,
                'Other names' => trim("{$e->fname} {$e->mname}"), 'Sex' => $e->gender, 'Date of birth' => $date($e->date_of_birth), 'Place of birth' => $e->place_of_birth,
                'Phone' => $e->phone_number, 'Email' => $e->email, 'Nationality' => $e->nationality, 'ID ('.($e->id_type ?: 'national ID').')' => $e->id_number, 'TIN' => $e->tin,
                'Marital status' => $e->marital_status, 'Home address' => $e->home_address,
                'Next of kin' => $kin ? trim($kin->fullname.($kin->relationship ? " ({$kin->relationship})" : '').($kin->phone ? ', '.$kin->phone : '')) : null,
            ],
            'children' => $dependants->filter(fn ($d) => strtolower((string) $d->relationship) === 'child')->values(),
        ]);
    }

    public static function clean(?string $html): ?string
    {
        return RichText::clean($html);
    }

    private function jobDescription(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return $text === strip_tags($text) ? nl2br(e(trim($text))) : RichText::clean($text);
    }

    public static function defaultTemplate(): string
    {
        return <<<'HTML'
<p>An agreement made on {today} BETWEEN <strong>{company_name}</strong> of {company_address} (hereinafter referred to as the "employer") of the one part AND <strong>{employee_name}</strong> (hereinafter referred to as the "employee") of the second part.</p>
<p><strong>WHEREBY THE PARTIES AGREE AS FOLLOWS:</strong></p>
<h3>1. Post held</h3>
<p>{company_name} appoints <u>{employee_name}</u> to the post of <u>{position}</u> in {department}.</p>
<h3>2. Duration and renewal</h3>
<p>The employee will remain in the service of the employer for a period of {period}, commencing on {start_date} and terminating on {end_date}. The contract period is subject to the financial capability of the employer; the contract may be terminated before its expiry, in which case the employer is not obliged to pay for the remaining part of the contract.</p>
<p>Three months before the expiry of this contract the employee must inform the employer in writing of the intention to renew it. Upon receipt of the request the employer may accept or reject it.</p>
<h3>3. Probation period</h3>
<p>The contract is subject to {probation_months} months probation upon recruitment. The employee's performance is assessed after the probation period; the probation may be extended, or the employee confirmed in the position, or the contract terminated through a written notice.</p>
<h3>4. Work station</h3>
<p>The employee's work station is {branch}, unless assigned duties elsewhere as deemed necessary by the employer.</p>
<h3>5. Supervisor</h3>
<p>The employee will be responsible and report to {supervisor}.</p>
<h3>6. Salary</h3>
<p>The employee will be paid a basic salary of {basic_salary} a month, less statutory deductions.</p>
<h3>7. Job description</h3>
<p>The employee shall perform the duties outlined in the job description attached to this agreement (Annex 1).</p>
<h3>8. Employment regulations</h3>
<p>The employee is subject to the Employment and Labour Relations Act No. 6 of 2004 as amended from time to time, complemented by the staff regulations and standing orders of the employer.</p>
<h3>9. Termination notice</h3>
<p>Either party may give one month's notice in advance to terminate the contract. For a serious cause either party may give notice with immediate effect, in which case the party giving notice pays one month's gross salary in lieu of notice.</p>
<h3>10. Leave</h3>
<p>The employee is entitled to 28 consecutive days of paid annual leave. Other leave (sick, maternity, paternity, compassionate) is granted as per the staff regulations and the Employment and Labour Relations Act No. 6 of 2004.</p>
<h3>11. Confidential information</h3>
<p>The employee shall not, directly or indirectly, use or disclose confidential information that comes to his or her knowledge in the course of this agreement, during or after the employment.</p>
HTML;
    }
}
