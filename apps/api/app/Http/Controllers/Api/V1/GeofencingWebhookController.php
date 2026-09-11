<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\Models\NetworkEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives CAMARA Geofencing CloudEvents pushed by the operator.
 *
 * The only unauthenticated write path in the product, and it is built to
 * deserve that exception:
 *
 *   * the URL carries an unguessable token, because Nokia signs nothing and
 *     an open endpoint that stores "network events" would let a stranger
 *     write records — the precise failure this product exists to argue
 *     against;
 *   * what it writes is never evidence. These rows enter no verification and
 *     no decision reads them. The worst a leaked token buys is a cluttered
 *     screen, not a changed verdict;
 *   * the body is stored exactly as delivered, so a later correction to how
 *     we read a field cannot rewrite what actually arrived.
 */
class GeofencingWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $expected = config('serviceproof.webhooks.geofencing_token');

        // hash_equals, not ===: a timing-safe comparison costs nothing and the
        // token is the only thing guarding this route.
        if (! $expected || ! hash_equals($expected, $token)) {
            // Deliberately 404 rather than 401. An endpoint that says
            // "wrong token" confirms it exists and invites guessing.
            return response()->json(['message' => 'Not found.'], 404);
        }

        $payload = $request->json()->all();
        $type = (string) ($payload['type'] ?? '');

        if (! str_contains($type, 'geofencing-subscriptions')) {
            Log::info('Geofencing webhook: ignoring unrecognised event type', ['type' => $type]);

            // 204, not an error. An operator retrying a delivery we chose not
            // to store helps nobody.
            return response()->json(null, 204);
        }

        $data = $payload['data'] ?? [];

        $event = NetworkEvent::create([
            'event_id' => $payload['id'] ?? null,
            'event_type' => $type,
            'spec_version' => $payload['specversion'] ?? null,
            'source' => $payload['source'] ?? null,
            'occurred_at' => isset($payload['time']) ? now()->parse($payload['time']) : null,
            'subscription_id' => $data['subscriptionId'] ?? null,
            'device_identifier' => $data['device']['phoneNumber'] ?? null,
            'provider' => 'NOKIA_NAC',
            'payload' => $payload,
            'received_at' => now(),
        ]);

        return response()->json(['id' => $event->id], 202);
    }
}
