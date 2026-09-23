<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationIndexRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(NotificationIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $notifications = $request->user()->notifications()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function markRead(Request $request, string $notification): NotificationResource
    {
        $ownedNotification = $this->ownedNotification($request, $notification);
        $ownedNotification->markAsRead();

        return new NotificationResource($ownedNotification->fresh());
    }

    /**
     * Update the specified resource in storage.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $request->user()->unreadNotifications()->update([
            'read_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'updated' => $updated,
                'unread_count' => 0,
            ],
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    private function ownedNotification(Request $request, string $id): DatabaseNotification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }
}
