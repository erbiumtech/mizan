<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A client registers the browser or app it wants pushed to — docs/desktop-mobile-plan.md A3.
 *
 * The body is the PushSubscription a browser hands back from `pushManager.subscribe()`, as JSON: an
 * endpoint and two keys. Posting the same endpoint again replaces the row, so a client may re-register on
 * every launch without piling up devices. Revocation is by id and only of the caller's own, like tokens.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->deviceTokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (DeviceToken $token): array => $token->toClient());

        return ApiResponse::success(['devices' => $devices->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
            'transport' => ['nullable', 'string', 'in:'.DeviceToken::TRANSPORT_WEBPUSH],
        ]);

        $token = DeviceToken::register($request->user(), $data['endpoint'], [
            'transport' => $data['transport'] ?? DeviceToken::TRANSPORT_WEBPUSH,
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return ApiResponse::success(['device' => $token->toClient()], $token->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * The panel's own unsubscribe — M2. A browser that has just called `unsubscribe()` knows its endpoint
     * and nothing else; the id was never shown to it.
     */
    public function destroyByEndpoint(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        $request->user()->deviceTokens()->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return ApiResponse::success(['message' => 'This device will no longer be pushed to.']);
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        $deleted = $request->user()->deviceTokens()->whereKey($device)->delete();

        abort_unless($deleted, 404, 'No such device.');

        return ApiResponse::success(['message' => 'That device will no longer be pushed to.']);
    }
}
