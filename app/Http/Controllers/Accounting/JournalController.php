<?php
namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $journals = JournalEntry::where('company_id', $this->ctx->id())
            ->with('lines')->latest('date')->paginate(20);
        return view('accounting.journals.index', compact('journals'));
    }

    public function create()
    {
        $accounts = Account::where('company_id', $this->ctx->id())
            ->where('is_active', true)->orderBy('code')->get();
        return view('accounting.journals.create', compact('accounts'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'date'               => 'required|date',
            'description'        => 'nullable|string',
            'reference_no'       => 'nullable|string|max:50',
            'lines'              => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
            'lines.*.debit'      => 'nullable|numeric|min:0',
            'lines.*.credit'     => 'nullable|numeric|min:0',
        ]);

        $totalDebit  = collect($request->lines)->sum(fn($l) => $l['debit'] ?? 0);
        $totalCredit = collect($request->lines)->sum(fn($l) => $l['credit'] ?? 0);

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return back()->withInput()->with('error', 'Journal entry must balance: total debits must equal total credits.');
        }

        DB::transaction(function () use ($request) {
            $entry = JournalEntry::create([
                'company_id'   => $this->ctx->id(),
                'reference_no' => $request->reference_no,
                'date'         => $request->date,
                'description'  => $request->description,
                'status'       => 'draft',
                'created_by'   => auth()->id(),
            ]);
            foreach ($request->lines as $line) {
                $entry->lines()->create([
                    'account_id'  => $line['account_id'],
                    'description' => $line['description'] ?? null,
                    'debit'       => $line['debit'] ?? 0,
                    'credit'      => $line['credit'] ?? 0,
                ]);
            }
        });

        return redirect()->route('accounting.journals.index')->with('success', 'Journal entry saved.');
    }

    public function show(JournalEntry $journal)
    {
        $journal->load('lines.account', 'creator');
        return view('accounting.journals.show', compact('journal'));
    }

    public function edit(JournalEntry $journal)
    {
        if ($journal->status === 'posted') {
            return back()->with('error', 'Posted journals cannot be edited.');
        }
        $journal->load('lines');
        $accounts = Account::where('company_id', $this->ctx->id())
            ->where('is_active', true)->orderBy('code')->get();
        return view('accounting.journals.edit', compact('journal', 'accounts'));
    }

    public function update(Request $request, JournalEntry $journal)
    {
        if ($journal->status === 'posted') {
            return back()->with('error', 'Posted journals cannot be edited.');
        }
        $request->validate([
            'date'               => 'required|date',
            'description'        => 'nullable|string',
            'reference_no'       => 'nullable|string|max:50',
            'lines'              => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
            'lines.*.debit'      => 'nullable|numeric|min:0',
            'lines.*.credit'     => 'nullable|numeric|min:0',
        ]);

        $totalDebit  = collect($request->lines)->sum(fn($l) => $l['debit'] ?? 0);
        $totalCredit = collect($request->lines)->sum(fn($l) => $l['credit'] ?? 0);

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return back()->withInput()->with('error', 'Journal entry must balance.');
        }

        DB::transaction(function () use ($request, $journal) {
            $journal->update([
                'reference_no' => $request->reference_no,
                'date'         => $request->date,
                'description'  => $request->description,
            ]);
            $journal->lines()->delete();
            foreach ($request->lines as $line) {
                $journal->lines()->create([
                    'account_id'  => $line['account_id'],
                    'description' => $line['description'] ?? null,
                    'debit'       => $line['debit'] ?? 0,
                    'credit'      => $line['credit'] ?? 0,
                ]);
            }
        });

        return redirect()->route('accounting.journals.show', $journal)->with('success', 'Journal updated.');
    }

    public function destroy(JournalEntry $journal)
    {
        if ($journal->status === 'posted') {
            return back()->with('error', 'Posted journals cannot be deleted.');
        }
        $journal->delete();
        return back()->with('success', 'Journal entry deleted.');
    }

    public function post(JournalEntry $journal)
    {
        if ($journal->status !== 'draft') {
            return back()->with('error', 'Only draft journals can be posted.');
        }
        $journal->update(['status' => 'posted']);
        return back()->with('success', 'Journal entry posted.');
    }
}
