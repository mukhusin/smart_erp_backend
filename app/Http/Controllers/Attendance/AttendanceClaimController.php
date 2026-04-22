<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceClaim;
use App\Models\AttendanceClaimReason;
use App\Models\AttendanceTransa;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AttendanceClaimController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = AttendanceClaim::with(['employee', 'reason', 'transaction'])
            ->whereHas('employee', fn($q) => $q->where('company_id', $this->ctx->id()));

        if ($request->filled('status')) {
            $query->where('claim_status', $request->status);
        }

        $claims = $query->orderByDesc('claim_date')->paginate(20)->withQueryString();
        return view('attendance.claims.index', compact('claims'));
    }

    public function create()
    {
        $transactions = AttendanceTransa::where('company_id', $this->ctx->id())
            ->where('is_claimed', false)->orderByDesc('transaction_date')->limit(200)->get();
        $reasons = AttendanceClaimReason::where('company_id', $this->ctx->id())->where('is_active', true)->get();
        return view('attendance.claims.create', compact('transactions', 'reasons'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'attendance_transa_id' => 'required|exists:attendance_transas,id',
            'claim_reason_id'      => 'required|exists:attendance_claim_reasons,id',
            'claim_date'           => 'required|date',
            'description'          => 'required|string',
            'remark'               => 'nullable|string',
        ]);

        $transa = AttendanceTransa::findOrFail($data['attendance_transa_id']);

        AttendanceClaim::create(array_merge($data, [
            'employee_id'  => $transa->employee_id,
            'lost_hour'    => $transa->lost_hour,
            'lost_amount'  => $transa->lost_amount,
            'percent'      => $transa->percent ?? 0,
            'claim_status' => 'pending',
        ]));

        return redirect()->route('attendance.claims.index')->with('success', 'Attendance claim submitted.');
    }

    public function show(AttendanceClaim $claim)
    {
        $claim->load(['employee', 'reason', 'transaction', 'approvedBy']);
        return view('attendance.claims.show', compact('claim'));
    }

    public function edit(AttendanceClaim $claim)
    {
        $reasons = AttendanceClaimReason::where('company_id', $this->ctx->id())->where('is_active', true)->get();
        return view('attendance.claims.edit', compact('claim', 'reasons'));
    }

    public function update(Request $request, AttendanceClaim $claim)
    {
        $data = $request->validate([
            'claim_status' => 'required|in:pending,approved,rejected',
            'comment'      => 'nullable|string',
            'hr_accepted'  => 'nullable|numeric|min:0',
        ]);

        if ($data['claim_status'] === 'approved') {
            $data['approved_by'] = auth()->user()->employee?->id;
            $data['approved_at'] = now()->toDateString();
        }

        $claim->update($data);
        return redirect()->route('attendance.claims.index')->with('success', 'Claim updated.');
    }

    public function destroy(AttendanceClaim $claim)
    {
        $claim->delete();
        return back()->with('success', 'Claim deleted.');
    }
}
