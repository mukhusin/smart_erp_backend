<?php

namespace Modules\Workflow\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Workflow\Http\Requests\WorkflowActionRequest;
use Modules\Workflow\Http\Resources\WorkflowInstanceResource;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Requests waiting for approval, and the requester's own requests.
 *
 * @tags Approvals
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /**
     * My approvals inbox.
     *
     * Requests waiting for the current user now (by role, handed-over role, name or as head of department).
     */
    public function inbox(): AnonymousResourceCollection
    {
        return WorkflowInstanceResource::collection($this->engine->inbox(request()->user()));
    }

    /**
     * Number of requests waiting for me.
     *
     * @response array{count: int}
     */
    public function count(): JsonResponse
    {
        return response()->json(['count' => $this->engine->inbox(request()->user())->count()]);
    }

    /**
     * My requests.
     *
     * Requests I started, newest first.
     */
    public function mine(): AnonymousResourceCollection
    {
        return WorkflowInstanceResource::collection(
            WorkflowInstance::where('initiated_by', request()->user()->id)->with(['steps.role', 'steps.user', 'steps.actor', 'subject', 'initiator'])->latest('id')->paginate(25)
        );
    }

    /**
     * Show a request.
     *
     * For the requester, its approvers (current or earlier) and workflow administrators.
     */
    public function show(WorkflowInstance $instance): WorkflowInstanceResource
    {
        $user = request()->user();
        $instance->load(['steps.role', 'steps.user', 'steps.actor', 'subject', 'initiator']);
        $allowed = $instance->initiated_by === $user->id
            || $this->engine->canAct($user, $instance)
            || $instance->steps->contains('acted_by', $user->id)
            || $user->can('admin.workflow.manage');
        abort_unless($allowed, 403, 'You are not part of this request.');

        return new WorkflowInstanceResource($instance);
    }

    /**
     * Approve the current step.
     */
    public function approve(WorkflowActionRequest $request, WorkflowInstance $instance): WorkflowInstanceResource
    {
        return $this->show($this->engine->approve($instance, $request->user(), $request->validated('comment')));
    }

    /**
     * Reject the request.
     *
     * Ends the request; a comment is required.
     */
    public function reject(WorkflowActionRequest $request, WorkflowInstance $instance): WorkflowInstanceResource
    {
        return $this->show($this->engine->reject($instance, $request->user(), $request->validated('comment')));
    }

    /**
     * Send back to an earlier step.
     */
    public function sendBack(WorkflowActionRequest $request, WorkflowInstance $instance): WorkflowInstanceResource
    {
        return $this->show($this->engine->sendBack($instance, $request->user(), (int) $request->validated('to_step'), $request->validated('comment')));
    }

    /**
     * Withdraw my request.
     */
    public function cancel(WorkflowInstance $instance): WorkflowInstanceResource
    {
        return $this->show($this->engine->cancel($instance, request()->user()));
    }
}
