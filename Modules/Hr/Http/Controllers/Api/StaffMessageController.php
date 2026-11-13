<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageRecipient;
use App\Models\Messaging;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Hr\Actions\SendStaffMessages;
use Modules\Hr\Http\Requests\StaffMessagePreviewRequest;
use Modules\Hr\Http\Requests\StaffMessageRequest;

/**
 * @tags HR: Messages
 */
class StaffMessageController extends Controller
{
    /**
     * Staff messages sent or scheduled (newest first), with delivery counts.
     */
    #[QueryParameter('type', description: 'SMS, Email or Notice', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    public function index(Request $request): JsonResponse
    {
        $page = Messaging::with('creator:id,username')->where('campaign', 'staff_message')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->withCount([
                'recipients',
                'recipients as sent_count' => fn ($q) => $q->where('status', MessageRecipient::STATUS_SENT),
                'recipients as failed_count' => fn ($q) => $q->where('status', MessageRecipient::STATUS_FAILED),
            ])
            ->latest('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Messaging $m) => $this->row($m))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * How many employees the chosen recipients cover and who cannot be reached (no phone, email or account).
     *
     * @response array{data: array{total: int, reachable: int, missing: list<string>}, sms_max_length: int}
     */
    public function preview(StaffMessagePreviewRequest $request, SendStaffMessages $messages): JsonResponse
    {
        return response()->json(['data' => $messages->preview($request->validated(), $request->user()), 'sms_max_length' => (int) config('sms.max_length', 120)]);
    }

    /**
     * Send a message now or schedule it. Employees outside the sender's scope are never included.
     */
    public function store(StaffMessageRequest $request, SendStaffMessages $messages): JsonResponse
    {
        $messaging = $messages->create($request->validated(), $request->user());

        return response()->json(['data' => $this->row($this->counted($messaging))], 201);
    }

    /**
     * A message with every recipient and their delivery status.
     */
    public function show(Messaging $message): JsonResponse
    {
        abort_unless($message->campaign === 'staff_message', 404);
        $message->load(['creator:id,username', 'recipients.employee:id,employee_no,fname,mname,lname']);

        return response()->json(['data' => $this->row($this->counted($message)) + [
            'recipients' => $message->recipients->map(fn (MessageRecipient $r) => [
                'id' => $r->id,
                'employee_no' => $r->employee?->employee_no,
                'name' => $r->employee ? trim("{$r->employee->fname} {$r->employee->lname}") : null,
                'address' => $r->recipient_address,
                'status' => $r->status,
                'error' => $r->response,
            ])->all(),
        ]]);
    }

    /**
     * Cancel a scheduled message that has not been sent yet.
     */
    public function cancel(Messaging $message, SendStaffMessages $messages): JsonResponse
    {
        abort_unless($message->campaign === 'staff_message', 404);

        return response()->json(['data' => $this->row($this->counted($messages->cancel($message)))]);
    }

    private function counted(Messaging $m): Messaging
    {
        return $m->loadCount([
            'recipients',
            'recipients as sent_count' => fn ($q) => $q->where('status', MessageRecipient::STATUS_SENT),
            'recipients as failed_count' => fn ($q) => $q->where('status', MessageRecipient::STATUS_FAILED),
        ])->loadMissing('creator:id,username');
    }

    private function row(Messaging $m): array
    {
        $audience = match ($m->recipient_type) {
            Messaging::RECIPIENT_INDIVIDUAL => 'Chosen employees',
            Messaging::RECIPIENT_DEPARTMENT => 'Department: '.($m->department?->name ?? '—'),
            Messaging::RECIPIENT_SECTION => 'Section: '.($m->section?->name ?? '—'),
            Messaging::RECIPIENT_BRANCH => 'Branch',
            default => 'All staff',
        };

        return [
            'id' => $m->id,
            'type' => $m->type,
            'subject' => $m->subject,
            'message' => $m->message,
            'audience' => $audience.(! empty($m->filters['emp_type']) ? ' · '.str_replace('_', ' ', $m->filters['emp_type']) : ''),
            'status' => $m->status,
            'scheduled_at' => $m->scheduled_at?->toIso8601String(),
            'sent_at' => $m->sent_at?->toIso8601String(),
            'recipients' => (int) $m->recipients_count,
            'sent' => (int) $m->sent_count,
            'failed' => (int) $m->failed_count,
            'skipped' => (int) $m->skipped_count,
            'created_by' => $m->creator?->username,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
