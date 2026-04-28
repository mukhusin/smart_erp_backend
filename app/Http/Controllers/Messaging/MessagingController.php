<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MessageRecipient;
use App\Models\Messaging;
use App\Services\CompanyContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessagingController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $messages = Messaging::with(['creator', 'recipients'])
            ->where('company_id', $this->ctx->id())
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('messaging.index', compact('messages'));
    }

    public function create()
    {
        $departments = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();
        $sections    = Department::where('company_id', $this->ctx->id())->whereNotNull('parent_id')->orderBy('name')->get();
        $employees   = Employee::where('company_id', $this->ctx->id())
            ->whereHas('activeContract')
            ->orderBy('fname')
            ->get();

        return view('messaging.create', compact('departments', 'sections', 'employees'));
    }

    public function store(Request $request)
    {
        $this->normalizeRecipientFields($request);

        $maxChars = $request->type === Messaging::TYPE_SMS ? 'max:120' : 'max:1000';

        $request->validate([
            'type'           => 'required|in:' . Messaging::TYPE_SMS . ',' . Messaging::TYPE_EMAIL,
            'subject'        => 'required_if:type,' . Messaging::TYPE_EMAIL . '|nullable|max:255',
            'message'        => "required|$maxChars",
            'extra'          => 'nullable|max:2000',
            'scheduled_at'   => 'required|date|after:' . Carbon::now()->addMinutes(30)->format('Y-m-d H:i'),
            'recipient_type' => 'required|in:individual,department,section,active_contracts',
            'employee_ids'   => 'required_if:recipient_type,individual|array',
            'employee_ids.*' => 'exists:employees,id',
            'department_id'  => 'nullable|required_if:recipient_type,department|exists:departments,id',
            'section_id'     => 'nullable|required_if:recipient_type,section|exists:departments,id',
        ]);

        $message = Messaging::create([
            'company_id'     => $this->ctx->id(),
            'type'           => $request->type,
            'subject'        => $request->subject,
            'message'        => $request->message,
            'extra'          => $request->extra,
            'scheduled_at'   => $request->scheduled_at,
            'recipient_type' => $request->recipient_type,
            'department_id'  => $request->recipient_type === Messaging::RECIPIENT_DEPARTMENT ? $request->department_id : null,
            'section_id'     => $request->recipient_type === Messaging::RECIPIENT_SECTION    ? $request->section_id    : null,
            'from_address'   => $this->fromAddress($request->type),
            'created_by'     => Auth::id(),
            'status'         => Messaging::STATUS_PENDING,
        ]);

        $this->addRecipients($message, $request);

        return redirect()->route('messaging.index')->with('success', 'Message scheduled successfully.');
    }

    public function show(Messaging $messaging)
    {
        abort_unless($messaging->company_id === $this->ctx->id(), 403);
        $messaging->load(['creator', 'recipients.employee', 'department', 'section']);
        return view('messaging.show', compact('messaging'));
    }

    public function edit(Messaging $messaging)
    {
        abort_unless($messaging->company_id === $this->ctx->id(), 403);
        abort_unless($messaging->status === Messaging::STATUS_PENDING, 403);

        $departments = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();
        $sections    = Department::where('company_id', $this->ctx->id())->whereNotNull('parent_id')->orderBy('name')->get();
        $employees   = Employee::where('company_id', $this->ctx->id())->whereHas('activeContract')->orderBy('fname')->get();

        return view('messaging.edit', compact('messaging', 'departments', 'sections', 'employees'));
    }

    public function update(Request $request, Messaging $messaging)
    {
        abort_unless($messaging->company_id === $this->ctx->id(), 403);
        abort_unless($messaging->status === Messaging::STATUS_PENDING, 403);

        $this->normalizeRecipientFields($request);

        $maxChars = $request->type === Messaging::TYPE_SMS ? 'max:120' : 'max:1000';

        $request->validate([
            'type'           => 'required|in:' . Messaging::TYPE_SMS . ',' . Messaging::TYPE_EMAIL,
            'subject'        => 'required_if:type,' . Messaging::TYPE_EMAIL . '|nullable|max:255',
            'message'        => "required|$maxChars",
            'extra'          => 'nullable|max:2000',
            'scheduled_at'   => 'required|date|after:' . Carbon::now()->addMinutes(30)->format('Y-m-d H:i'),
            'recipient_type' => 'required|in:individual,department,section,active_contracts',
            'employee_ids'   => 'required_if:recipient_type,individual|array',
            'employee_ids.*' => 'exists:employees,id',
            'department_id'  => 'nullable|required_if:recipient_type,department|exists:departments,id',
            'section_id'     => 'nullable|required_if:recipient_type,section|exists:departments,id',
        ]);

        $messaging->update([
            'type'           => $request->type,
            'subject'        => $request->subject,
            'message'        => $request->message,
            'extra'          => $request->extra,
            'scheduled_at'   => $request->scheduled_at,
            'recipient_type' => $request->recipient_type,
            'department_id'  => $request->recipient_type === Messaging::RECIPIENT_DEPARTMENT ? $request->department_id : null,
            'section_id'     => $request->recipient_type === Messaging::RECIPIENT_SECTION    ? $request->section_id    : null,
        ]);

        $messaging->recipients()->delete();
        $this->addRecipients($messaging, $request);

        return redirect()->route('messaging.show', $messaging)->with('success', 'Message updated.');
    }

    public function destroy(Messaging $messaging)
    {
        abort_unless($messaging->company_id === $this->ctx->id(), 403);
        abort_unless($messaging->status === Messaging::STATUS_PENDING, 403);

        $messaging->recipients()->delete();
        $messaging->delete();

        return redirect()->route('messaging.index')->with('success', 'Message deleted.');
    }

    // ── AJAX helpers ────────────────────────────────────────────────────────

    public function employeesByDepartment(Request $request)
    {
        return Employee::where('company_id', $this->ctx->id())
            ->where('department_id', $request->department_id)
            ->whereHas('activeContract')
            ->get(['id', 'fname', 'mname', 'lname']);
    }

    public function employeesBySection(Request $request)
    {
        return Employee::where('company_id', $this->ctx->id())
            ->where('section_id', $request->section_id)
            ->whereHas('activeContract')
            ->get(['id', 'fname', 'mname', 'lname']);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function normalizeRecipientFields(Request $request): void
    {
        match ($request->recipient_type) {
            'individual'       => $request->merge(['department_id' => null, 'section_id' => null]),
            'department'       => $request->merge(['section_id' => null]),
            'section'          => $request->merge(['department_id' => null]),
            'active_contracts' => $request->merge(['department_id' => null, 'section_id' => null]),
            default            => null,
        };
    }

    private function fromAddress(string $type): string
    {
        return $type === Messaging::TYPE_EMAIL
            ? config('mail.from.address', 'noreply@example.com')
            : config('sms.from', 'ERP');
    }

    private function addRecipients(Messaging $message, Request $request): void
    {
        $employees = match ($request->recipient_type) {
            'individual'       => Employee::whereIn('id', $request->employee_ids ?? [])->whereHas('activeContract')->get(),
            'department'       => Employee::where('department_id', $request->department_id)->whereHas('activeContract')->get(),
            'section'          => Employee::where('section_id', $request->section_id)->whereHas('activeContract')->get(),
            'active_contracts' => Employee::where('company_id', $this->ctx->id())->whereHas('activeContract')->get(),
            default            => collect(),
        };

        foreach ($employees as $emp) {
            $address = $message->type === Messaging::TYPE_EMAIL
                ? ($emp->user?->email ?? $emp->email)
                : ($emp->phone_number ?? null);

            if (empty($address)) continue;

            MessageRecipient::create([
                'messaging_id'      => $message->id,
                'employee_id'       => $emp->id,
                'recipient_address' => $address,
                'status'            => MessageRecipient::STATUS_PENDING,
            ]);
        }
    }
}
