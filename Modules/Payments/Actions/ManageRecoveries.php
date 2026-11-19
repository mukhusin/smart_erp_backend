<?php

namespace Modules\Payments\Actions;

use App\Models\OtherDeduction;
use App\Models\PayrollSheet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\PayrollPostingSetting;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Actions\ManagePayItems;
use Modules\Payments\Models\ImprestRecovery;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentSetting;

/** Unretired imprest taken back from the holder's salary as monthly deductions; each approved payroll clears staff advances. */
class ManageRecoveries
{
    public function __construct(private readonly ManagePayItems $payItems, private readonly PostingService $posting, private readonly BudgetService $budgets) {}

    public function start(PaymentRequest $r, int $months, string $startMonth, ?string $reason, User $by): ImprestRecovery
    {
        if (! $r->isImprest() || $r->status !== 'paid') {
            throw ValidationException::withMessages(['request' => 'Only a paid imprest that is not retired can be recovered from salary.']);
        }
        if ($r->retirements()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['request' => 'A retirement of this imprest is waiting for approval; decide it first.']);
        }
        $holder = $r->holder;
        if (! $holder) {
            throw ValidationException::withMessages(['request' => 'The imprest has no holder in HR to deduct from.']);
        }
        $start = Carbon::parse($startMonth.'-01');
        if ($start->lt(today()->startOfMonth())) {
            throw ValidationException::withMessages(['start_month' => 'Start this month or later.']);
        }
        $amount = round((float) $r->total, 2);
        $each = floor($amount / $months * 100) / 100;
        $last = round($amount - $each * ($months - 1), 2);
        $name = "Imprest {$r->number} not retired";

        return DB::transaction(function () use ($r, $holder, $months, $start, $reason, $by, $amount, $each, $last, $name) {
            $ids = [];
            if ($months > 1 && $each > 0) {
                $ids[] = $this->payItems->addDeduction($holder, ['name' => $name, 'amount' => $each, 'start_month' => $start->format('Y-m'), 'months' => $months - 1, 'description' => $reason], $by)->id;
            }
            $ids[] = $this->payItems->addDeduction($holder, ['name' => $name.($months > 1 ? ' (last)' : ''), 'amount' => $months > 1 ? $last : $amount,
                'start_month' => $start->copy()->addMonthsNoOverflow($months - 1)->format('Y-m'), 'months' => 1, 'description' => $reason], $by)->id;
            $recovery = $r->recoveries()->create(['deduction_ids' => $ids, 'amount' => $amount, 'months' => $months, 'start_month' => $start->toDateString(),
                'status' => 'active', 'reason' => $reason, 'created_by' => $by->id]);
            $r->update(['status' => 'recovering']);
            $holder->user?->notify(new Notice('Imprest recovered from salary', ':number :amount was not retired and will be deducted from your salary over :months month(s) from :from.', "/payments/requests/{$r->id}", 'dash-circle',
                params: ['number' => $r->number, 'amount' => number_format($amount, 2), 'months' => $months, 'from' => $start->format('F Y')]));

            return $recovery;
        });
    }

    public function cancel(ImprestRecovery $recovery, User $by): void
    {
        if ($recovery->status !== 'active' || $recovery->recovered > 0) {
            throw ValidationException::withMessages(['recovery' => 'Only a recovery with nothing deducted yet can be cancelled.']);
        }
        DB::transaction(function () use ($recovery, $by) {
            foreach ($recovery->deductions()->whereNull('deleted_at')->get() as $d) {
                $d->status === 'pending' ? $this->payItems->delete($d, $by) : $d->delete();
            }
            $recovery->update(['status' => 'cancelled']);
            $recovery->request->update(['status' => 'paid']);
        });
    }

    /** Called when a payroll is approved: records what each active recovery took that month and clears staff advances. */
    public function payrollApproved(PayrollSheet $sheet, ?User $by): void
    {
        $recoveries = ImprestRecovery::with('request.holder')->where('status', 'active')->get();
        if ($recoveries->isEmpty()) {
            return;
        }
        $employees = $sheet->details()->pluck('employee_id')->flip();
        $settings = PayrollPostingSetting::current();
        $payable = $settings->account('other') ?? $settings->account('net_pay');
        $advances = PaymentSetting::current()->imprest_account_id;
        $date = app(\Modules\Accounting\Services\PayrollPosting::class)->date($sheet);
        foreach ($recoveries as $recovery) {
            $r = $recovery->request;
            if (! $employees->has($r->holder_employee_id) || $recovery->entries()->where('payroll_sheet_id', $sheet->id)->exists()) {
                continue;
            }
            $taken = round($recovery->deductions()->whereNull('deleted_at')->get()->filter(fn (OtherDeduction $d) => $d->appliesTo((int) $sheet->year, (int) $sheet->month))->sum('amount'), 2);
            $amount = min($taken, $recovery->remaining());
            if ($amount <= 0) {
                continue;
            }
            $actor = $by ?? $recovery->creator;
            DB::transaction(function () use ($recovery, $r, $sheet, $amount, $payable, $advances, $date, $actor) {
                $journal = null;
                if ($payable && $advances) {
                    $journal = $this->posting->post($date, "Imprest {$r->number} recovered from salary ({$sheet->name})", [
                        ['account_id' => $payable, 'debit' => $amount, 'description' => "Salary deduction: {$r->holder?->full_name}"],
                        ['account_id' => $advances, 'credit' => $amount, 'description' => "Imprest {$r->number} recovered"],
                    ], $actor, 'imprest', $r);
                }
                $recovery->entries()->create(['payroll_sheet_id' => $sheet->id, 'amount' => $amount, 'journal_entry_id' => $journal?->id]);
                $recovery->update(['recovered' => round($recovery->recovered + $amount, 2)]);
                if ($recovery->remaining() <= 0) {
                    $recovery->update(['status' => 'completed']);
                    $this->budgets->release($r, $actor);
                    $r->update(['status' => 'recovered']);
                }
            });
        }
    }
}
