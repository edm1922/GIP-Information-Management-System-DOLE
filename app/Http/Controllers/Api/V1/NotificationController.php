<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\NotificationResource;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        // `unread_count` powers the SPA topbar badge without a second request;
        // it is not derivable from the paginated page alone.
        $payload = NotificationResource::collection($notifications)->response()->getData(true);
        $payload['unread_count'] = $request->user()->unreadNotifications()->count();

        return response()->json($payload);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();

        if ($notification->read_at === null) {
            $notification->markAsRead();

            AuditService::log('updated', 'Marked notification as read.', null, [
                'notification_id' => $notification->id,
                'notification_type' => $notification->type,
                'read_at' => optional($notification->fresh()->read_at)?->toDateTimeString(),
            ]);
        }

        $data = $notification->data ?? [];
        $targetUrl = null;
        if (isset($data['url_route'])) {
            try {
                $targetUrl = route($data['url_route'], $data['url_params'] ?? [], false);
            } catch (\Throwable $e) {
                $targetUrl = data_get($data, 'url');
            }
        } else {
            $targetUrl = data_get($data, 'url');
        }

        // Match the web controller fallback: unresolved notifications return the
        // notifications index so the SPA always has somewhere to navigate.
        $targetUrl ??= route('notifications.index', [], false);

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => new NotificationResource($notification->fresh()),
            'target_url' => $targetUrl,
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $unreadNotifications = $request->user()->unreadNotifications;
        $affectedCount = $unreadNotifications->count();

        if ($affectedCount > 0) {
            $unreadNotifications->markAsRead();

            AuditService::log('updated', 'Marked all unread notifications as read.', null, [
                'affected_count' => $affectedCount,
            ]);
        }

        return response()->json([
            'message' => 'All notifications marked as read.',
            'affected_count' => $affectedCount,
        ]);
    }
}
