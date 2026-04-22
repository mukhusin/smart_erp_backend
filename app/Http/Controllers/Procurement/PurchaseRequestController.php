<?php
namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PurchaseRequest;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseRequestController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = PurchaseRequest::where('company_id', $this->ctx->id())
            ->with('department', 'requester', 'items');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $requests = $query->latest('date')->paginate(20);
        return view('procurement.requests.index', compact('requests'));
    }

    public function create()
    {
        $departments = Department::where('company_id', $this->ctx->id())->orderBy('name')->get();
        $employees   = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('procurement.requests.create', compact('departments', 'employees'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'department_id'       => 'nullable|exists:departments,id',
            'requested_by'        => 'nullable|exists:employees,id',
            'pr_number'           => 'nullable|string|max:60',
            'date'                => 'required|date',
            'notes'               => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.item_name'   => 'required|string|max:200',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit'        => 'nullable|string|max:30',
            'items.*.estimated_cost' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($request) {
            $pr = PurchaseRequest::create([
                'company_id'    => $this->ctx->id(),
                'department_id' => $request->department_id,
                'requested_by'  => $request->requested_by,
                'pr_number'     => $request->pr_number,
                'date'          => $request->date,
                'notes'         => $request->notes,
                'status'        => 'pending',
            ]);
            foreach ($request->items as $item) {
                $pr->items()->create($item);
            }
        });

        return redirect()->route('procurement.requests.index')->with('success', 'Purchase request created.');
    }

    public function show(PurchaseRequest $request)
    {
        $request->load('department', 'requester', 'items');
        return view('procurement.requests.show', ['pr' => $request]);
    }

    public function edit(PurchaseRequest $request)
    {
        if ($request->status === 'ordered') {
            return back()->with('error', 'Cannot edit an already ordered request.');
        }
        $request->load('items');
        $departments = Department::where('company_id', $this->ctx->id())->orderBy('name')->get();
        $employees   = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('procurement.requests.edit', ['pr' => $request, 'departments' => $departments, 'employees' => $employees]);
    }

    public function update(Request $req, PurchaseRequest $request)
    {
        $req->validate([
            'department_id'       => 'nullable|exists:departments,id',
            'requested_by'        => 'nullable|exists:employees,id',
            'date'                => 'required|date',
            'notes'               => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.item_name'   => 'required|string|max:200',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit'        => 'nullable|string|max:30',
            'items.*.estimated_cost' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($req, $request) {
            $request->update([
                'department_id' => $req->department_id,
                'requested_by'  => $req->requested_by,
                'date'          => $req->date,
                'notes'         => $req->notes,
            ]);
            $request->items()->delete();
            foreach ($req->items as $item) {
                $request->items()->create($item);
            }
        });

        return redirect()->route('procurement.requests.show', $request)->with('success', 'Request updated.');
    }

    public function destroy(PurchaseRequest $request)
    {
        if ($request->status === 'ordered') {
            return back()->with('error', 'Cannot delete an ordered request.');
        }
        $request->delete();
        return back()->with('success', 'Purchase request deleted.');
    }

    public function approve(PurchaseRequest $request)
    {
        $request->update(['status' => 'approved']);
        return back()->with('success', 'Request approved.');
    }

    public function reject(PurchaseRequest $request)
    {
        $request->update(['status' => 'rejected']);
        return back()->with('success', 'Request rejected.');
    }
}
