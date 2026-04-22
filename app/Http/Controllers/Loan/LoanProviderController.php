<?php

namespace App\Http\Controllers\Loan;

use App\Http\Controllers\Controller;
use App\Models\LoanProvider;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class LoanProviderController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $providers = LoanProvider::where('company_id', $this->ctx->id())
            ->orderBy('name')->paginate(25);
        return view('loans.providers.index', compact('providers'));
    }

    public function create()
    {
        return view('loans.providers.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'short_name'  => 'nullable|string|max:30',
            'description' => 'nullable|string',
        ]);

        LoanProvider::create(array_merge($data, ['company_id' => $this->ctx->id(), 'is_active' => true]));
        return redirect()->route('loans.providers.index')->with('success', 'Loan provider created.');
    }

    public function edit(LoanProvider $provider)
    {
        return view('loans.providers.edit', compact('provider'));
    }

    public function update(Request $request, LoanProvider $provider)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'short_name'  => 'nullable|string|max:30',
            'description' => 'nullable|string',
        ]);

        $provider->update(array_merge($data, ['is_active' => $request->boolean('is_active')]));
        return redirect()->route('loans.providers.index')->with('success', 'Loan provider updated.');
    }

    public function destroy(LoanProvider $provider)
    {
        $provider->delete();
        return back()->with('success', 'Loan provider deleted.');
    }
}
