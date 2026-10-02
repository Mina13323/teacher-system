<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Push\WebPushEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'endpoint' => [
                'required',
                'url',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! WebPushEndpoint::isAllowed($value)) {
                        $fail('The endpoint must use HTTPS and a configured push-service host.');
                    }
                },
            ],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $max = max(1, min(50, (int) config('push.max_subscriptions_per_user', 10)));
        $endpoint = $data['endpoint'];
        $attributes = [
            'keys' => ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
            'user_agent' => substr((string) $request->userAgent(), 0, 500) ?: null,
        ];

        // Endpoint URLs are globally unique because they identify a browser
        // destination. Never upsert by endpoint while changing user_id: a caller
        // who submits another account's endpoint must not take it over.
        $saveOwnedSubscription = function () use ($endpoint, $user, $attributes, $max): PushSubscription {
            return DB::transaction(function () use ($endpoint, $user, $attributes, $max): PushSubscription {
                // Serialize registrations for one account before checking both
                // global endpoint ownership and the per-account endpoint cap.
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $subscription = PushSubscription::query()
                    ->where('endpoint', $endpoint)
                    ->lockForUpdate()
                    ->first();

                if ($subscription && (int) $subscription->user_id !== (int) $lockedUser->getKey()) {
                    abort(409, 'This push endpoint is already registered to another account.');
                }

                $subscription ??= new PushSubscription(['endpoint' => $endpoint]);
                $subscription->user_id = $lockedUser->getKey();
                $subscription->keys = $attributes['keys'];
                $subscription->user_agent = $attributes['user_agent'];
                $subscription->save();

                // Keep the newest N endpoints (including the just-saved row)
                // while still enforcing the limit under concurrent requests.
                do {
                    $overflowIds = $lockedUser->pushSubscriptions()
                        ->where('id', '!=', $subscription->getKey())
                        ->orderByDesc('created_at')
                        ->skip($max - 1)
                        ->limit(500)
                        ->pluck('id');

                    if ($overflowIds->isNotEmpty()) {
                        $lockedUser->pushSubscriptions()->whereIn('id', $overflowIds)->get()->each->delete();
                    }
                } while ($overflowIds->isNotEmpty());

                return $subscription;
            });
        };

        try {
            $subscription = $saveOwnedSubscription();
        } catch (QueryException $exception) {
            // A concurrent first registration may win the unique endpoint key
            // before this transaction. Re-read after rollback; only retry when
            // that row belongs to this same user.
            $existing = PushSubscription::query()->where('endpoint', $endpoint)->first();
            if (! $existing) {
                throw $exception;
            }
            if ((int) $existing->user_id !== (int) $user->getKey()) {
                abort(409, 'This push endpoint is already registered to another account.');
            }
            $subscription = $saveOwnedSubscription();
        }

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
