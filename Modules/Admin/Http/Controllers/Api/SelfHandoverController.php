<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\RoleHandover;
use Modules\Core\Services\Handovers;

/**
 * Employees hand their own roles to a colleague for a period (approved in the "Role handover" workflow).
 *
 * @tags Self service: Handovers
 */
class SelfHandoverController extends Controller
{
    public function __construct(private readonly Handovers $handovers) {}

    /**
     * Handovers I gave and received, and the roles I can hand over.
     *
     * @response array{data: list<array{id: int, direction: string, role: string, other: string, starts_on: string, ends_on: string|null, reason: string, status: string, status_label: string, source: string, suspend_giver: bool, leave_id: int|null, waiting_for: string|null, decision_comment: string|null, can_cancel: bool}>, meta: array{roles: list<array{id: int, name: string}>}}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = RoleHandover::with(['role', 'fromUser.employee', 'toUser.employee', 'workflow.steps'])
            ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))->latest('starts_at')->limit(100)->get();

        return response()->json([
            'data' => $rows->map(fn (RoleHandover $h) => $this->row($h, $user))->values(),
            'meta' => ['roles' => $this->handovers->ownRoles($user)->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()],
        ]);
    }

    /**
     * Colleagues to hand over to.
     *
     * @response array{data: list<array{id: int, label: string, detail: string|null}>}
     */
    #[QueryParameter('search', type: 'string')]
    public function colleagues(Request $request): JsonResponse
    {
        $user = $request->user();
        $t = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;
        $users = User::with('employee.jobPosition', 'employee.department')->where('company_id', $user->company_id)->where('is_active', true)->whereKeyNot($user->id)
            ->whereHas('employee', fn ($q) => $q->where('employment_status', '!=', 'terminated')
                ->when($t, fn ($q) => $q->where(fn ($q) => $q->where('fname', 'like', "%{$t}%")->orWhere('lname', 'like', "%{$t}%")->orWhere('employee_no', 'like', "%{$t}%"))))
            ->limit(20)->get();

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->id, 'label' => $u->display_name,
            'detail' => collect([$u->employee?->employee_no, $u->employee?->jobPosition?->title, $u->employee?->department?->name])->filter()->implode(' · ') ?: null])->values()]);
    }

    /**
     * Hand over one of my roles; it goes for approval.
     *
     * @response array{data: array{id: int, status: string}}
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'role_id' => ['required', 'integer'],
            'to_user_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            /** The last day of the handover. */
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'reason' => ['required', 'string', 'max:500'],
            /** I cannot use the role while it is handed over (default yes). */
            'suspend_giver' => ['nullable', 'boolean'],
        ]);
        $h = $this->handovers->request($request->user(), $d);

        return response()->json(['data' => ['id' => $h->id, 'status' => $h->status]], 201);
    }

    /**
     * Withdraw a request, or end a handover early.
     *
     * @response array{data: array{id: int, status: string}}
     */
    public function cancel(Request $request, RoleHandover $handover): JsonResponse
    {
        $h = $this->handovers->cancel($handover, $request->user());

        return response()->json(['data' => ['id' => $h->id, 'status' => $h->state()]]);
    }

    private function row(RoleHandover $h, User $me): array
    {
        $given = $h->from_user_id === $me->id;
        $state = $h->state();

        return [
            'id' => $h->id, 'direction' => $given ? 'given' : 'received', 'role' => $h->role?->name ?? '', 'other' => ($given ? $h->toUser : $h->fromUser)?->display_name ?? '',
            'starts_on' => $h->starts_at->toDateString(), 'ends_on' => $h->ends_at?->copy()->subDay()->toDateString(), 'reason' => $h->reason,
            'status' => $state, 'status_label' => ['active' => 'In effect', 'upcoming' => 'Upcoming', 'ended' => 'Ended'][$state] ?? (RoleHandover::STATUS_LABELS[$state] ?? $state),
            'source' => $h->source, 'suspend_giver' => (bool) $h->suspend_giver, 'leave_id' => $h->emp_leave_id,
            'waiting_for' => $state === 'pending' ? $h->workflow?->steps?->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $h->decision_comment,
            'can_cancel' => $given && (in_array($state, ['active', 'upcoming'], true) || ($state === 'pending' && ! $h->emp_leave_id)),
        ];
    }
}
