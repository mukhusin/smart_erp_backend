<?php

namespace Modules\Hr\Jobs;

use App\Models\Employee;
use App\Services\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Hr\Actions\ManageDevicePersonnel;

/** Registers one employee (or, without one, every current employee with attendance) on the company's fingerprint devices. */
class PushStaffToDeviceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $companyId, public readonly ?int $employeeId = null)
    {
        $this->onQueue('attendance');
    }

    public function handle(CompanyContext $context, ManageDevicePersonnel $device): void
    {
        $context->run($this->companyId, function () use ($device) {
            if ($this->employeeId === null) {
                $device->pushAll();

                return;
            }
            $employee = Employee::find($this->employeeId);
            if ($employee) {
                $device->push($employee);
            }
        });
    }
}
