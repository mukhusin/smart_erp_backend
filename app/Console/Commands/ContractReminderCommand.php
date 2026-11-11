<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanySmsSetting;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\MessageRecipient;
use App\Services\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Sms\PhoneNumber;
use Modules\Core\Sms\SmsService;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * SMS to employees whose contract ends within 90 days and who have no renewal waiting (HRMS
 * `app:sms-notify-three-month-contract`): at most 3 messages per contract end date, at least 28 days apart.
 */
class ContractReminderCommand extends Command
{
    public const CAMPAIGN = 'contract_expiry';

    private const MAX_MESSAGES = 3;

    private const DAYS_BETWEEN = 28;

    protected $signature = 'hr:contract-reminders';

    protected $description = 'Send SMS reminders to employees whose contract ends within 90 days';

    public function handle(CompanyContext $context, SmsService $sms): int
    {
        $sent = 0;
        $companies = CompanySmsSetting::withoutCompanyScope()->where('is_enabled', true)->where('contract_reminders', true)->pluck('company_id');

        foreach (Company::whereIn('id', $companies)->pluck('id') as $companyId) {
            $sent += $context->run($companyId, fn () => $this->remind($sms));
        }

        $this->info("Contract reminders sent: {$sent}");

        return self::SUCCESS;
    }

    private function remind(SmsService $sms): int
    {
        $today = CarbonImmutable::today();
        $contracts = EmployeeContract::with('employee')
            ->whereIn('employee_id', Employee::where('employment_status', '!=', 'terminated')->whereNotNull('phone_number')->select('id'))
            ->where('is_active', true)
            ->whereBetween('end_date', [$today->toDateString(), $today->addDays(O::RENEWAL_WINDOW_DAYS)->toDateString()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_contracts as w')->whereColumn('w.employee_id', 'employee_contracts.employee_id')
                ->where('w.status', 'waiting')->whereNull('w.deleted_at'))
            ->get();

        $sent = 0;
        foreach ($contracts as $contract) {
            $employee = $contract->employee;
            $key = "{$contract->id}:{$contract->end_date->toDateString()}";
            if (! PhoneNumber::tanzania($employee->phone_number) || ! $this->due($employee->id, $key, $today)) {
                continue;
            }

            $days = (int) $today->diffInDays($contract->end_date, false);
            $message = sprintf('Hi %s, contract ends on %s (%d days left). Please contact HR.', $employee->full_name ?: 'Colleague', $contract->end_date->toDateString(), max($days, 0));
            $sent += $sms->send('Contract Expiry Reminder', [['employee_id' => $employee->id, 'phone' => $employee->phone_number, 'message' => $message]], self::CAMPAIGN, $key) ? 1 : 0;
        }

        return $sent;
    }

    private function due(int $employeeId, string $key, CarbonImmutable $today): bool
    {
        $previous = MessageRecipient::where('employee_id', $employeeId)
            ->whereHas('messaging', fn ($q) => $q->where('campaign', self::CAMPAIGN)->where('campaign_key', $key))
            ->where('status', '!=', MessageRecipient::STATUS_FAILED);

        if ((clone $previous)->count() >= self::MAX_MESSAGES) {
            return false;
        }
        $last = (clone $previous)->latest('created_at')->value('created_at');

        return ! $last || CarbonImmutable::parse($last)->lt($today->subDays(self::DAYS_BETWEEN));
    }
}
