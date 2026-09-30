<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web Push subscriptions for the signed-in user (P2).
 *
 * Endpoints are per-user and re-subscribing the same endpoint upserts
 * (idempotent). Unsubscribing only ever touches the caller's own rows.
 */
class PushSubscriptionController extends Controller
{
    /** List own subscriptions + the VAPID public key the browser needs. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => config('push.vapid_private_key') !== null && config('push.vapid_private_key') !== '',
                'public_key' => config('push.vapid_public_key'),
                'subscriptions' => $user->pushSubscriptions()->get(['id', 'endpoint', 'user_agent', 'created_at']),
            ],
        ]);
    }

    /** Store (or refresh) a browser PushSubscription JSON payload. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $max = (int) config('push.max_subscriptions_per_user', 10);

        /** @var PushSubscription $subscription */
        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $user->getKey(),
                'keys' => ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
                'user_agent' => substr((string) $request->userAgent(), 0, 500) ?: null,
            ]
        );

        // Keep at most N endpoints per user (drop the oldest).
        $overflow = $user->pushSubscriptions()
            ->where('id', '!=', $subscription->getKey())
            ->orderByDesc('created_at')
            ->skip($max - 1)
            ->take(1000)
            ->get();
        foreach ($overflow as $old) {
            $old->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Push subscription saved.',
            'data' => ['id' => $subscription->getKey()],
        ], 201);
    }

    /** Delete one of the caller's subscriptions (never another user's). */
    public function destroy(Request $request, PushSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $request->user()->getKey(), 403);

        $subscription->delete();

        return response()->json([
            'success' => true,
            'message' => 'Push subscription removed.',
            'data' => null,
        ]);
    }
}
