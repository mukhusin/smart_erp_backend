<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Requests\PayrollPaymentRequest;
use Modules\Accounting\Http\Requests\PayrollPostingRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\PayrollPostingSetting;
use Modules\Accounting\Services\PayrollPosting;
use Modules\Core\Services\AccessService;

/**
 * @tags Accounting: payroll
 */
class PayrollPostingController extends Controller
{
    public function __construct(private readonly PayrollPosting $posting, private readonly AccessService $access) {}

    /** @response array{data: array<string, int|bool|null>, labels: array<string, string>, accounts: list<array{id: int, code: string, name: string, type: string}>} */
    public function settings(): JsonResponse
    {
        $s = PayrollPostingSetting::current();
        $data = ['enabled' => (bool) $s->enabled];
        foreach (array_keys(PayrollPostingSetting::ACCOUNTS) as $key) {
            $data["{$key}_account_id"] = $s->account($key);
        }

        return response()->json(['data' => $data, 'labels' => PayrollPostingSetting::ACCOUNTS,
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->whereIn('type', ['expense', 'liability'])->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values()]);
    }

    /** @response array{data: array<string, int|bool|null>, labels: array<string, string>, accounts: list<array{id: int, code: string, name: string, type: string}>} */
    public function updateSettings(PayrollPostingRequest $request): JsonResponse
    {
        PayrollPostingSetting::current()->fill($request->validated())->save();

        return $this->settings();
    }

    /**
     * Ledger state of a payroll: its posting journal, net pay payment and any posting error.
     *
     * @response array{data: array{enabled: bool, posted: array{id: int, reference_no: string|null}|null, paid: array{id: int, reference_no: string|null}|null, error: string|null, net: float, can: array{post: bool, pay: bool}}}
     */
    public function show(Request $request, PayrollSheet $sheet): JsonResponse
    {
        $journal = $sheet->journal_entry_id ? JournalEntry::find($sheet->journal_entry_id) : null;
        $payment = $sheet->payment_journal_id ? JournalEntry::find($sheet->payment_journal_id) : null;
        $can = $this->access->can($request->user(), 'accounting.journals');

        return response()->json(['data' => [
            'enabled' => (bool) PayrollPostingSetting::current()->enabled, 'posted' => $journal ? ['id' => $journal->id, 'reference_no' => $journal->reference_no] : null,
            'paid' => $payment ? ['id' => $payment->id, 'reference_no' => $payment->reference_no] : null, 'error' => $sheet->posting_error,
            'net' => round((float) $sheet->details()->sum('net_salary'), 2),
            'can' => ['post' => $can && $sheet->status === 'approved' && ! $journal, 'pay' => $can && $journal && ! $payment],
        ]]);
    }

    /** @response array{data: array{id: int, reference_no: string|null}} */
    public function post(Request $request, PayrollSheet $sheet): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'accounting.journals'), 403);
        $j = $this->posting->post($sheet, $request->user());

        return response()->json(['data' => ['id' => $j->id, 'reference_no' => $j->reference_no]]);
    }

    /** @response array{data: array{id: int, reference_no: string|null}} */
    public function pay(PayrollPaymentRequest $request, PayrollSheet $sheet): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'accounting.journals'), 403);
        $j = $this->posting->pay($sheet, (int) $request->validated('cash_bank_account_id'), $request->validated('date'), $request->user());

        return response()->json(['data' => ['id' => $j->id, 'reference_no' => $j->reference_no]]);
    }
}
