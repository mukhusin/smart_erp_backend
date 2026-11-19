<?php

namespace Modules\Hr\Jobs;

use App\Models\Company;
use App\Models\PayrollSheet;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Actions\ManagePayroll;
use Throwable;

/** Works a payroll sheet out in the background and tells the person who asked when it is ready (or why it failed). */
class GeneratePayrollJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $sheetId, public readonly ?int $userId) {}

    public function uniqueId(): string
    {
        return 'payroll-sheet-'.$this->sheetId;
    }

    public function handle(CompanyContext $context, ManagePayroll $payroll): void
    {
        $sheet = PayrollSheet::withoutGlobalScopes()->find($this->sheetId);
        if (! $sheet) {
            return;
        }
        $context->run(Company::findOrFail($sheet->company_id), function () use ($sheet, $payroll) {
            $sheet->forceFill(['processing_started_at' => null])->save();
            $done = $payroll->generate($sheet->refresh());
            $done->forceFill(['processing_error' => null])->save();
            User::find($this->userId)?->notify(new Notice('Payroll ready', ':name is worked out: :count staff, net :net.', "/payroll/sheets/{$done->id}", 'cash-stack',
                params: ['name' => $done->name, 'count' => $done->employees_count, 'net' => number_format((float) $done->net_total, 2)]));
        });
    }

    public function failed(Throwable $e): void
    {
        $sheet = PayrollSheet::withoutGlobalScopes()->find($this->sheetId);
        if (! $sheet) {
            return;
        }
        $reason = $e instanceof ValidationException ? collect($e->errors())->flatten()->join(' ') : 'Something went wrong while working out the payroll. Try again or contact support.';
        $sheet->forceFill(['processing_started_at' => null, 'processing_error' => $reason])->save();
        report($e);
        app(CompanyContext::class)->run(Company::find($sheet->company_id), fn () => User::find($this->userId)?->notify(new Notice('Payroll not worked out', ':name: :reason', "/payroll/sheets/{$sheet->id}", 'exclamation-triangle', params: ['name' => $sheet->name, 'reason' => $reason])));
    }
}
