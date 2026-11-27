<?php

namespace Database\Seeders\Demo;

use App\Models\Department;
use App\Models\Employee;
use Modules\Appraisals\Models\Appraisal;
use Modules\Workflow\Models\WorkflowInstance;

class DemoPeopleSeeder
{
    public function __construct(private readonly Demo $demo) {}

    public function run(): void
    {
        $this->appraisals();
        $this->tasks();
        $this->audit();
    }

    private function appraisals(): void
    {
        $d = $this->demo;
        $d->api('PUT', 'appraisal-settings', ['scale' => 'percent', 'mid_year' => true, 'parts' => [
            ['key' => 'tasks', 'label' => 'Tasks and targets', 'weight' => 50, 'enabled' => true], ['key' => 'competencies', 'label' => 'Competencies', 'weight' => 40, 'enabled' => true],
            ['key' => 'attendance', 'label' => 'Attendance', 'weight' => 10, 'enabled' => true],
        ], 'bands' => [['min' => 85, 'label' => 'Outstanding'], ['min' => 70, 'label' => 'Very good'], ['min' => 55, 'label' => 'Good'], ['min' => 0, 'label' => 'Needs improvement']]], 'hrmanager');
        $comp = [];
        foreach (['Teamwork', 'Communication', 'Patient focus', 'Integrity'] as $c) {
            $comp[] = $d->id($d->api('POST', 'appraisal-competencies', ['name' => $c], 'hrmanager'));
        }
        $comp = array_values(array_filter($comp));
        $cycle = $d->id($d->api('POST', 'appraisal-cycles', ['name' => 'Performance year 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'mid_year_date' => '2026-06-30'], 'hrmanager'));
        if (! $cycle) {
            return;
        }
        $d->api('POST', "appraisal-cycles/$cycle/open", [], 'hrmanager');
        $scores = fn (int $base) => ['tasks' => min(100, $base + 5), 'attendance' => min(100, $base + 10), 'competencies' => array_combine($comp, array_map(fn ($i) => min(100, $base + $i * 3 - 3), array_keys($comp)))];
        foreach (Appraisal::with('employee.user')->orderBy('id')->get() as $i => $a) {
            $user = $a->employee?->user;
            if (! $user || $i % 3 === 2) {
                continue;
            }
            $base = 58 + ($i * 7) % 35;
            if (! $d->api('POST', "appraisals/{$a->id}/self", ['scores' => $scores($base), 'comment' => 'I met most of my targets this year.', 'submit' => true], $user, true)) {
                continue;
            }
            if ($i % 3 === 1) {
                continue;
            }
            for ($round = 0; $round < 4; $round++) {
                $status = $a->refresh()->status;
                if ($status !== 'review') {
                    break;
                }
                $instance = WorkflowInstance::where('process', 'appraisal')->where('subject_id', $a->id)->where('status', 'pending')->latest('id')->first();
                $reviewer = $instance ? $d->approverFor($instance) : null;
                if (! $reviewer || ! $d->api('POST', "appraisals/{$a->id}/review", ['scores' => $scores($base - 4), 'comment' => 'Good performance; keep improving documentation.', 'decision' => 'approve'], $reviewer, true)) {
                    break;
                }
            }
            if ($a->refresh()->status === 'acknowledge' && $i % 2 === 0) {
                $d->api('POST', "appraisals/{$a->id}/acknowledge", ['agrees' => true, 'comment' => 'Thank you'], $user, true);
            }
        }
    }

    private function tasks(): void
    {
        $d = $this->demo;
        $work = [
            'nursing' => ['Wards', [['Monthly hand hygiene audit - Ward A', 'high'], ['Update patient care plans', 'normal'], ['Restock emergency trolleys', 'high'], ['Night shift handover checklist', 'low']]],
            'medical' => ['Surgery', [['Prepare theatre list for next week', 'high'], ['Mortality review presentation', 'normal'], ['Update surgical safety checklist', 'normal']]],
            'finmanager' => ['Accounts', [['Bank reconciliation for October', 'high'], ['Prepare VAT return', 'high'], ['Follow up NHIF rejected claims', 'normal']]],
            'hrmanager' => ['Recruitment', [['Shortlist nurse applicants', 'high'], ['Update staff files', 'low'], ['Prepare CPD training calendar', 'normal']]],
            'procmanager' => ['Procurement', [['Supplier performance review', 'normal'], ['Quarterly stock count', 'high']]],
            'ict' => [null, [['Backup HMIS database', 'high'], ['Replace faulty network switch in OPD', 'normal']]],
        ];
        foreach ($work as $boss => [$section, $list]) {
            $head = $d->user($boss)->employee;
            $staff = Employee::where('department_id', $head->department_id)->where('id', '!=', $head->id)
                ->when($section, fn ($q) => $q->where('section_id', Department::where('name', $section)->value('id')))->orderBy('id')->limit(6)->pluck('id')->all();
            if (! $staff) {
                $staff = Employee::where('department_id', $head->department_id)->where('id', '!=', $head->id)->limit(4)->pluck('id')->all();
            }
            foreach ($list as $n => [$title, $priority]) {
                $res = $d->api('POST', 'tasks', ['title' => $title, 'priority' => $priority, 'due_date' => now()->addDays(($n - 1) * 5)->toDateString(), 'employee_ids' => [$staff[$n % max(1, count($staff))]]], $boss);
                $id = $res['data'][0]['id'] ?? null;
                if (! $id) {
                    continue;
                }
                $assignee = Employee::find($staff[$n % max(1, count($staff))])?->user;
                if (! $assignee || $n === 3) {
                    continue;
                }
                $d->api('POST', "tasks/$id/start", [], $assignee, true);
                if ($n === 0) {
                    $d->api('POST', "tasks/$id/complete", ['comment' => 'Done and filed'], $assignee, true);
                    $d->api('POST', "tasks/$id/review", ['accept' => true], $boss, true);
                } elseif ($n === 1) {
                    $d->api('POST', "tasks/$id/updates", ['note' => 'About a quarter done', 'progress' => 25], $assignee, true);
                }
            }
        }
    }

    private function audit(): void
    {
        $d = $this->demo;
        foreach ([['Payment voucher support for audit fee', 'Please share the engagement letter and board approval for the audit fee payment.', 'accountant', 'payments', '/payments/vouchers'],
            ['Stock count differences', 'Explain the variance between the system balance and the count of surgical gloves.', 'store', 'stores', '/inventory/stock'],
            ['Overtime approvals', 'Several overtime claims in Nursing exceed 4 hours. Please confirm they were planned.', 'hrmanager', 'hr', '/overtime/claims']] as $n => [$title, $q, $to, $area, $link]) {
            $id = $d->id($d->api('POST', 'audit/queries', ['title' => $title, 'question' => $q, 'assigned_to' => $d->user($to)->id, 'link' => $link, 'area' => $area, 'due_date' => now()->addDays(7 + $n * 3)->toDateString()], 'auditor'));
            if ($id && $n === 0) {
                $d->api('POST', "audit/queries/$id/respond", ['response' => 'The engagement letter and board minutes are attached to the payment request.'], $to);
                $d->api('POST', "audit/queries/$id/close", ['note' => 'Agreed, support is adequate.'], 'auditor');
            } elseif ($id && $n === 1) {
                $d->api('POST', "audit/queries/$id/respond", ['response' => 'Two boxes were damaged by water; an adjustment is in progress.'], $to);
            }
        }
        $d->api('POST', 'audit/engagements', ['firm' => 'Demo Audit Partners', 'auditor_name' => 'External Auditor', 'email' => 'auditor@audit-partners.example',
            'period_from' => '2025-07-01', 'period_to' => '2026-06-30', 'access_until' => now()->addMonths(2)->toDateString()], 'hr');
        $d->settle('audit-engagement');
    }
}
