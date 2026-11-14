<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ExcuseOfDuty;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Hr\Actions\ManageSickSheets;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeScope;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sick sheets (excuse of duty): employees send them in self-service, HR enters them on an employee's behalf.
 *
 * @tags HR: Sick sheets
 */
class SickSheetController extends Controller
{
    public function __construct(private readonly ManageSickSheets $sheets, private readonly EmployeeScope $scope) {}

    /**
     * Sick sheets overlapping a month (default this month), of the employees I may see. `status`: pending, active, ended, rejected, cancelled.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, start_date: string, end_date: string, number_of_days: int, hospital_name: string|null, doctor_name: string|null, reason: string|null, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, file: array{name: string, url: string}|null, can_cancel: bool, created_at: string|null}>}
     */
    #[QueryParameter('month', type: 'string', example: '2026-11')]
    #[QueryParameter('status', type: 'string')]
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'status' => ['nullable', 'in:pending,active,ended,rejected,cancelled']]);
        $from = Carbon::createFromFormat('Y-m-d', ($d['month'] ?? now()->format('Y-m')).'-01')->startOfMonth();
        $rows = ExcuseOfDuty::with(['employee.department', 'workflow.steps'])
            ->whereHas('employee', fn ($q) => $this->scope->apply($q, $request->user()))
            ->whereDate('start_date', '<=', $from->copy()->endOfMonth())->whereDate('end_date', '>=', $from)->orderByDesc('start_date')->get()
            ->filter(fn ($s) => empty($d['status']) || $s->shownStatus() === $d['status']);

        return response()->json(['data' => $rows->map(fn ($s) => $this->row($s, $request, false))->values()]);
    }

    /**
     * Enter a sick sheet for an employee (multipart when attaching the sheet: PDF, JPG or PNG up to 5 MB).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, start_date: string, end_date: string, number_of_days: int, hospital_name: string|null, doctor_name: string|null, reason: string|null, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, file: array{name: string, url: string}|null, can_cancel: bool, created_at: string|null}}
     */
    public function storeFor(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $employee), 404);
        $sheet = $this->sheets->submit($employee, $this->validated($request), $request->file('file'), $request->user(), 'hr');

        return response()->json(['data' => $this->row($sheet->load(['employee.department', 'workflow.steps']), $request, false)], 201);
    }

    /** Cancel an approved sick sheet, or withdraw one I entered that is still waiting. */
    public function cancel(Request $request, ExcuseOfDuty $sheet): JsonResponse
    {
        abort_unless($sheet->employee && $this->scope->allows($request->user(), $sheet->employee), 404);

        return response()->json(['data' => $this->row($this->sheets->cancel($sheet, $request->user())->load(['employee.department', 'workflow.steps']), $request, false)]);
    }

    /** The attached sick sheet. */
    public function file(Request $request, ExcuseOfDuty $sheet): StreamedResponse
    {
        abort_unless($sheet->file_path && $sheet->employee && $this->scope->allows($request->user(), $sheet->employee), 404);

        return EmployeeFiles::response($sheet->file_path, $sheet->file_name ?? 'sick-sheet');
    }

    /**
     * My sick sheets (newest first).
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, start_date: string, end_date: string, number_of_days: int, hospital_name: string|null, doctor_name: string|null, reason: string|null, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, file: array{name: string, url: string}|null, can_cancel: bool, created_at: string|null}>}
     */
    public function mine(Request $request): JsonResponse
    {
        $rows = ExcuseOfDuty::with('workflow.steps')->where('employee_id', $this->me($request)->id)->orderByDesc('start_date')->get();

        return response()->json(['data' => $rows->map(fn ($s) => $this->row($s, $request, true))->values()]);
    }

    /**
     * Send my sick sheet to HR (multipart when attaching the sheet: PDF, JPG or PNG up to 5 MB).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, start_date: string, end_date: string, number_of_days: int, hospital_name: string|null, doctor_name: string|null, reason: string|null, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, file: array{name: string, url: string}|null, can_cancel: bool, created_at: string|null}}
     */
    public function storeMine(Request $request): JsonResponse
    {
        $sheet = $this->sheets->submit($this->me($request), $this->validated($request, true), $request->file('file'), $request->user(), 'employee');

        return response()->json(['data' => $this->row($sheet->load('workflow.steps'), $request, true)], 201);
    }

    /** Withdraw my sick sheet while it is waiting for approval. */
    public function withdrawMine(Request $request, ExcuseOfDuty $sheet): JsonResponse
    {
        abort_unless($sheet->employee_id === $this->me($request)->id, 404);
        abort_unless($sheet->status === 'pending', 422, 'Only a sick sheet that is waiting can be withdrawn.');

        return response()->json(['data' => $this->row($this->sheets->cancel($sheet, $request->user())->load('workflow.steps'), $request, true)]);
    }

    /** The sick sheet I attached. */
    public function myFile(Request $request, ExcuseOfDuty $sheet): StreamedResponse
    {
        abort_unless($sheet->employee_id === $this->me($request)->id && $sheet->file_path, 404);

        return EmployeeFiles::response($sheet->file_path, $sheet->file_name ?? 'sick-sheet');
    }

    private function validated(Request $request, bool $self = false): array
    {
        return $request->validate([
            'start_date' => ['required', 'date', $self ? 'before_or_equal:'.now()->addDays(30)->toDateString() : 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'hospital_name' => ['nullable', 'string', 'max:150'], 'doctor_name' => ['nullable', 'string', 'max:150'], 'reason' => ['nullable', 'string', 'max:1000'],
            'file' => [$self ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], ['file.required' => 'Attach the sick sheet from the doctor (photo or PDF).']);
    }

    private function row(ExcuseOfDuty $s, Request $request, bool $self): array
    {
        $status = $s->shownStatus();
        $instance = $s->workflow;
        $step = $instance?->status === 'pending' ? $instance->steps->firstWhere('status', 'pending') : null;
        $e = $s->employee;

        return [
            'id' => $s->id,
            'employee' => $self || ! $e ? null : ['id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'department' => $e->department?->name],
            'start_date' => $s->start_date->toDateString(), 'end_date' => $s->end_date->toDateString(), 'number_of_days' => (int) $s->number_of_days,
            'hospital_name' => $s->hospital_name, 'doctor_name' => $s->doctor_name, 'reason' => $s->reason,
            'status' => $status, 'status_label' => ExcuseOfDuty::STATUS_LABELS[$status] ?? $status, 'source' => $s->source ?? 'hr',
            'waiting_for' => $step?->name, 'decision_comment' => $s->decision_comment,
            'file' => $s->file_path ? ['name' => $s->file_name ?? 'sick-sheet', 'url' => route($self ? 'self.sick-sheets.file' : 'hr.sick-sheets.file', $s->id, false)] : null,
            'can_cancel' => $self ? $status === 'pending' : ($status === 'active' || ($status === 'pending' && $instance?->initiated_by === $request->user()->id)),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');

        return $employee;
    }
}
