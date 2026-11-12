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
     * @response array{data: list<array{id: string, title: string, message: string, link: string|null, icon: string|null, read: bool, created_at: string}>, meta: array{unread: int}}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->latest()->limit(30)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->data['title'] ?? '', 'message' => $n->data['message'] ?? '', 'link' => $n->data['link'] ?? null,
                'icon' => $n->data['icon'] ?? null, 'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String(),
            ]),
            'meta' => ['unread' => $user->unreadNotifications()->count()],
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

        return response()->json(['meta' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }
}
