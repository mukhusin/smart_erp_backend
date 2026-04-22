<?php
namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = JournalEntry::where('company_id', $this->ctx->id())
            ->where('status', 'posted')
            ->with('lines.account');

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }
        if ($request->filled('account_id')) {
            $query->whereHas('lines', fn($q) => $q->where('account_id', $request->account_id));
        }

        $transactions = $query->latest('date')->paginate(25);
        $accounts = Account::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();

        return view('accounting.transactions.index', compact('transactions', 'accounts'));
    }
}
