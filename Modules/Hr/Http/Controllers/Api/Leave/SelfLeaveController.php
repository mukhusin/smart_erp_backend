<?php

namespace Modules\Hr\Http\Controllers\Api\Leave;

use App\Http\Controllers\Controller;
use App\Models\EmpLeave;
use App\Models\EmpLeaveFile;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Hr\Actions\ManageLeave;
use Modules\Hr\Http\Requests\LeaveRequestRequest;
use Modules\Hr\Http\Resources\LeaveRequestResource;
use Modules\Hr\Support\EmployeeFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * My leave requests (self service).
 *
 * @tags Self service: My leave
 */
class SelfLeaveController extends Controller
{
    public function __construct(private readonly ManageLeave $leave) {}

    /**
     * My leave requests, newest first.
     *
     * @response array{data: list<LeaveRequestResource>}
     */
    public function index(Request $request): JsonResponse
    {
        $rows = EmpLeave::with(['leaveType', 'files', 'workflow.steps'])->where('employee_id', $this->me($request)->id)->latest('start_date')->latest('id')->get();

        return response()->json(['data' => LeaveRequestResource::collection($rows)->resolve($request)]);
    }

    /**
     * Request leave. Sent as multipart form data when attaching documents (PDF, JPG or PNG, up to 5 MB each, at most 5).
     */
    public function store(LeaveRequestRequest $request): LeaveRequestResource
    {
        $leave = $this->leave->request($this->me($request), $request->safe()->except('files'), $request->user(), 'employee', $request->file('files', []));

        return new LeaveRequestResource($leave->load(['leaveType', 'files', 'workflow.steps']));
    }

    /** Withdraw a request waiting for approval, or cancel approved leave that has not started. */
    public function withdraw(Request $request, EmpLeave $leave): LeaveRequestResource
    {
        abort_unless($leave->employee_id === $this->me($request)->id, 404);

        return new LeaveRequestResource($this->leave->withdraw($leave, $request->user())->load(['leaveType', 'files', 'workflow.steps']));
    }

    /** Download an attachment of my request. */
    public function file(Request $request, EmpLeave $leave, EmpLeaveFile $file): StreamedResponse
    {
        abort_unless($leave->employee_id === $this->me($request)->id && $file->emp_leave_id === $leave->id, 404);

        return EmployeeFiles::response($file->file_path, $file->file_name);
    }

    /**
     * Regions and districts to choose where I will be on leave, and my home region (from my branch).
     *
     * @response array{data: array{home: string|null, regions: list<array{region: string, districts: list<string>}>}}
     */
    public function places(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $regions = \Modules\Core\Support\Places::for(\App\Models\Company::find($employee->company_id)?->country);

        return response()->json(['data' => ['home' => $this->leave->homeRegion($employee),
            'regions' => collect($regions)->map(fn ($d, $r) => ['region' => $r, 'districts' => $d])->values()]]);
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');

        return $employee;
    }
}
