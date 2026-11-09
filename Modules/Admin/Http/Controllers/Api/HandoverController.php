<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Http\Requests\StoreHandoverRequest;
use Modules\Admin\Http\Resources\HandoverResource;
use Modules\Core\Models\RoleHandover;
use Modules\Core\Services\AccessService;

/**
 * Hand a role to another user for a period (e.g. while the role holder is on leave).
 *
 * @tags Admin: Handovers
 */
class HandoverController extends Controller
{
    /**
     * List handovers.
     */
    #[QueryParameter('filter[status]', description: '`current` (active and upcoming, default) or `all`.', type: 'string')]
    public function index(): AnonymousResourceCollection
    {
        $query = RoleHandover::with(['role', 'fromUser', 'toUser'])->latest('starts_at');
        if (request('filter.status', 'current') === 'current') {
            $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
        }

        return HandoverResource::collection($query->paginate(50));
    }

    /**
     * Hand over a role.
     */
    public function store(StoreHandoverRequest $request, AccessService $access)
    {
        $handover = RoleHandover::create($request->validated() + ['created_by' => $request->user()->id]);
        $access->forget();

        return (new HandoverResource($handover->load(['role', 'fromUser', 'toUser'])))->response()->setStatusCode(201);
    }

    /**
     * End a handover now.
     */
    public function revoke(RoleHandover $handover, AccessService $access): HandoverResource
    {
        if ($handover->revoked_at || ($handover->ends_at && $handover->ends_at <= now())) {
            throw ValidationException::withMessages(['handover' => 'This handover has already ended.']);
        }
        $handover->update(['revoked_at' => now(), 'revoked_by' => request()->user()->id]);
        $access->forget();

        return new HandoverResource($handover->load(['role', 'fromUser', 'toUser']));
    }
}
