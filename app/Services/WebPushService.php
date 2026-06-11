<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envoi de notifications Web Push à tous les appareils abonnés.
 *
 * Mono-utilisateur : pas de ciblage par user, on notifie tous les endpoints
 * enregistrés (téléphone, desktop…). Les abonnements expirés (404/410)
 * sont purgés automatiquement après chaque envoi.
 */
class WebPushService
{
    public function isConfigured(): bool
    {
        return (bool) config('webpush.vapid.public_key')
            && (bool) config('webpush.vapid.private_key');
    }

    /**
     * @param  array  $data  Données passées au service worker (session_id, type…)
     */
    public function broadcast(string $title, string $body, array $data = []): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $subscriptions = PushSubscription::all();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            ...$data,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                $payload,
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            } elseif (! $report->isSuccess()) {
                Log::warning('Web Push : échec d\'envoi', [
                    'endpoint' => $report->getEndpoint(),
                    'reason' => $report->getReason(),
                ]);
            }
        }
    }
}
