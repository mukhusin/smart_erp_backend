<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\PublicHoliday;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class PublicHolidayController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $holidays = PublicHoliday::where('company_id', $this->ctx->id())
            ->orderBy('month_number')->orderBy('day_number')->get();
        return view('leave.public-holidays.index', compact('holidays'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'        => 'required|string|max:150',
            'day_number'   => 'required|integer|min:1|max:31',
            'month_number' => 'required|integer|min:1|max:12',
        ]);

        PublicHoliday::create(array_merge($data, ['company_id' => $this->ctx->id(), 'is_active' => true]));
        return back()->with('success', 'Public holiday added.');
    }

    public function update(Request $request, PublicHoliday $publicHoliday)
    {
        $data = $request->validate([
            'title'        => 'required|string|max:150',
            'day_number'   => 'required|integer|min:1|max:31',
            'month_number' => 'required|integer|min:1|max:12',
        ]);
        $publicHoliday->update(array_merge($data, ['is_active' => $request->boolean('is_active')]));
        return back()->with('success', 'Public holiday updated.');
    }

    public function destroy(PublicHoliday $publicHoliday)
    {
        $publicHoliday->delete();
        return back()->with('success', 'Public holiday deleted.');
    }
}
