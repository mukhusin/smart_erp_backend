<?php

namespace Modules\Core\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The logged-in user's notifications (e.g. "Contract renewed").
 *
 * @tags Me: Notifications
 */
class NotificationController extends Controller
{
    /**
     * Latest notifications and the number unread.
     *
     * Notices past their expiry (staff messages with an end date) are left out.
     *
     * @response array{data: list<array{id: string, title: string, message: string, link: string|null, icon: string|null, read: bool, created_at: string, expires_at: string|null}>, meta: array{unread: int}}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->live($user->notifications())->latest()->limit(30)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => \App\Support\Locale::render($n->data, 'title'), 'message' => \App\Support\Locale::render($n->data, 'message'), 'link' => $n->data['link'] ?? null,
                'icon' => $n->data['icon'] ?? null, 'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String(),
                'expires_at' => isset($n->data['expires_at']) ? \Illuminate\Support\Carbon::parse($n->data['expires_at'])->toIso8601String() : null,
            ]),
            'meta' => ['unread' => $this->live($user->unreadNotifications())->count()],
        ]);
    }

    /**
     * Mark notifications as read (all, or the given ids).
     *
     * @response array{meta: array{unread: int}}
     */
    public function read(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['string']])['ids'] ?? null;
        $request->user()->unreadNotifications()->when($ids, fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

        return response()->json(['meta' => ['unread' => $this->live($request->user()->unreadNotifications())->count()]]);
    }

    private function live($query)
    {
        return $query->where(fn ($q) => $q->whereNull('data->expires_at')->orWhere('data->expires_at', '>', now()->format('Y-m-d H:i:s')));
    }
}
