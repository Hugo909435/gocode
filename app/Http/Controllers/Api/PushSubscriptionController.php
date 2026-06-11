<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Clé publique VAPID — nécessaire au navigateur pour s'abonner.
     * Renvoie enabled=false si les clés ne sont pas configurées côté serveur.
     */
    public function key(WebPushService $webPush): JsonResponse
    {
        return response()->json([
            'enabled' => $webPush->isConfigured(),
            'key' => config('webpush.vapid.public_key'),
        ]);
    }

    /**
     * Enregistre (ou réenregistre) l'abonnement push de l'appareil courant.
     * Le payload correspond à PushSubscription.toJSON() côté navigateur.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
            ],
        );

        return response()->json(['message' => 'Subscribed'], 201);
    }

    /**
     * Supprime l'abonnement de l'appareil courant (désactivation des notifs).
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        PushSubscription::where('endpoint', $validated['endpoint'])->delete();

        return response()->json(['message' => 'Unsubscribed']);
    }
}
