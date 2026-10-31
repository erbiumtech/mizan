<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;

/**
 * The bell, for a client — docs/desktop-mobile-plan.md A4. Versioned surface only.
 *
 * The same rows the panel's bell reads (database notifications, landlord-side, per user), flattened from
 * the Filament message shape to what a list on a phone draws: a title, a body, where a tap goes, and
 * whether it has been seen. Marking read is per notification and for all, and only ever the caller's own.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications()->latest();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->limit(min(100, max(1, (int) $request->query('limit', 50))))->get();

        return ApiResponse::success([
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'count' => $notifications->count(),
            'data' => $notifications->map(fn (DatabaseNotification $notification): array => $this->shape($notification))->values()->all(),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        /** @var DatabaseNotification|null $row */
        $row = $request->user()->notifications()->whereKey($notification)->first();

        abort_unless($row, 404, 'No such notification.');

        $row->markAsRead();

        return ApiResponse::success(['data' => $this->shape($row->fresh())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $marked = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['marked' => $marked]);
    }

    /** @return array<string, mixed> */
    private function shape(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;
        $url = Arr::first(array_filter(array_map(fn ($action) => $action['url'] ?? null, $data['actions'] ?? [])));

        return [
            'id' => $notification->getKey(),
            'title' => trim(strip_tags((string) ($data['title'] ?? ''))),
            'body' => trim(strip_tags((string) ($data['body'] ?? ''))),
            'status' => $data['status'] ?? null,
            'url' => $url,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
