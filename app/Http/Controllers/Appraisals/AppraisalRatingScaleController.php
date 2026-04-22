<?php
namespace App\Http\Controllers\Appraisals;

use App\Http\Controllers\Controller;
use App\Models\AppraisalRatingScale;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AppraisalRatingScaleController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $scales = AppraisalRatingScale::where('company_id', $this->ctx->id())
            ->orderBy('minimum_percent')->get();
        return view('appraisals.scales.index', compact('scales'));
    }

    public function create()
    {
        return view('appraisals.scales.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'scale'           => 'required|string|max:50',
            'minimum_percent' => 'required|numeric|min:0|max:100',
            'maximum_percent' => 'required|numeric|min:0|max:100|gte:minimum_percent',
            'description'     => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        AppraisalRatingScale::create($data);
        return redirect()->route('appraisals.scales.index')->with('success', 'Rating scale created.');
    }

    public function edit(AppraisalRatingScale $scale)
    {
        return view('appraisals.scales.edit', compact('scale'));
    }

    public function update(Request $request, AppraisalRatingScale $scale)
    {
        $data = $request->validate([
            'scale'           => 'required|string|max:50',
            'minimum_percent' => 'required|numeric|min:0|max:100',
            'maximum_percent' => 'required|numeric|min:0|max:100|gte:minimum_percent',
            'description'     => 'nullable|string',
        ]);
        $scale->update($data);
        return redirect()->route('appraisals.scales.index')->with('success', 'Rating scale updated.');
    }

    public function destroy(AppraisalRatingScale $scale)
    {
        $scale->delete();
        return back()->with('success', 'Rating scale deleted.');
    }
}
