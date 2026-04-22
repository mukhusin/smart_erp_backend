<?php
namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class ChartOfAccountController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $accounts = Account::with('parent')
            ->where('company_id', $this->ctx->id())
            ->orderBy('code')->orderBy('name')
            ->get();
        return view('accounting.chart.index', compact('accounts'));
    }

    public function create()
    {
        $parents = Account::where('company_id', $this->ctx->id())
            ->whereNull('parent_id')->orderBy('name')->get();
        return view('accounting.chart.create', compact('parents'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'        => 'nullable|string|max:30',
            'name'        => 'required|string|max:150',
            'type'        => 'required|in:asset,liability,equity,revenue,expense',
            'parent_id'   => 'nullable|exists:accounts,id',
            'description' => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        Account::create($data);
        return redirect()->route('accounting.chart.index')->with('success', 'Account created.');
    }

    public function show(Account $chart)
    {
        $chart->load(['parent', 'children', 'journalLines.journalEntry']);
        return view('accounting.chart.show', compact('chart'));
    }

    public function edit(Account $chart)
    {
        $parents = Account::where('company_id', $this->ctx->id())
            ->where('id', '!=', $chart->id)->whereNull('parent_id')->orderBy('name')->get();
        return view('accounting.chart.edit', compact('chart', 'parents'));
    }

    public function update(Request $request, Account $chart)
    {
        $data = $request->validate([
            'code'        => 'nullable|string|max:30',
            'name'        => 'required|string|max:150',
            'type'        => 'required|in:asset,liability,equity,revenue,expense',
            'parent_id'   => 'nullable|exists:accounts,id',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);
        $chart->update($data);
        return redirect()->route('accounting.chart.index')->with('success', 'Account updated.');
    }

    public function destroy(Account $chart)
    {
        if ($chart->journalLines()->count()) {
            return back()->with('error', 'Cannot delete an account with journal entries.');
        }
        $chart->delete();
        return back()->with('success', 'Account deleted.');
    }
}
