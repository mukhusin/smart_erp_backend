<?php
namespace App\Http\Controllers\Bills;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $payments = BillPayment::with('bill.supplier')
            ->whereHas('bill', fn($q) => $q->where('company_id', $this->ctx->id()))
            ->latest('payment_date')->paginate(25);
        return view('bills.payments.index', compact('payments'));
    }

    public function create(Request $request)
    {
        $bill = $request->filled('bill_id') ? Bill::find($request->bill_id) : null;
        $bills = Bill::where('company_id', $this->ctx->id())
            ->whereIn('status', ['open', 'partial'])
            ->with('supplier')->orderBy('due_date')->get();
        return view('bills.payments.create', compact('bills', 'bill'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'bill_id'        => 'required|exists:bills,id',
            'payment_date'   => 'required|date',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,cheque,mobile_money,other',
            'reference'      => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        DB::transaction(function () use ($data) {
            BillPayment::create($data);
            $bill = Bill::find($data['bill_id']);
            $bill->increment('paid_amount', $data['amount']);
            $bill->refresh();
            if ($bill->paid_amount >= $bill->total_amount) {
                $bill->update(['status' => 'paid']);
            } elseif ($bill->paid_amount > 0) {
                $bill->update(['status' => 'partial']);
            }
        });

        return redirect()->route('bills.payments.index')->with('success', 'Payment recorded.');
    }

    public function show(BillPayment $payment)
    {
        $payment->load('bill.supplier');
        return view('bills.payments.show', compact('payment'));
    }

    public function destroy(BillPayment $payment)
    {
        DB::transaction(function () use ($payment) {
            $bill = $payment->bill;
            $bill->decrement('paid_amount', $payment->amount);
            $bill->refresh();
            if ($bill->paid_amount <= 0) {
                $bill->update(['status' => 'open', 'paid_amount' => 0]);
            } elseif ($bill->paid_amount < $bill->total_amount) {
                $bill->update(['status' => 'partial']);
            }
            $payment->delete();
        });
        return back()->with('success', 'Payment reversed.');
    }
}
