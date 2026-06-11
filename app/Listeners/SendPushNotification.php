<?php

namespace App\Listeners;

use App\Agent\Events\AgentEventDispatched;
use App\Models\Session;
use App\Services\WebPushService;
use Throwable;

/**
 * Notifie le téléphone quand une session atteint un état qui mérite
 * l'attention de l'utilisateur : tâche finie, erreur, ou confirmation requise.
 *
 * Cas d'usage cible : lancer une tâche depuis le mobile, verrouiller l'écran,
 * être prévenu quand c'est terminé (le polling SSE/HTTP meurt écran éteint).
 */
class SendPushNotification
{
    /** Types d'événements agent qui déclenchent une notification */
    private const NOTIFIABLE_TYPES = ['done', 'error', 'confirmation_request'];

    public function __construct(
        private readonly WebPushService $webPush,
    ) {}

    public function handle(AgentEventDispatched $event): void
    {
        $agentEvent = $event->event;
        $type = $agentEvent->type->value;

        if (! in_array($type, self::NOTIFIABLE_TYPES) || ! $this->webPush->isConfigured()) {
            return;
        }

        $session = Session::with('project')->find($agentEvent->sessionId);
        $context = $session?->title
            ?? $session?->project?->name
            ?? 'Session';

        [$title, $body] = match ($type) {
            'done' => ["✓ {$context}", 'Tâche terminée avec succès.'],
            'error' => ["✗ {$context}", $this->truncate($agentEvent->payload['message'] ?? 'Une erreur est survenue.')],
            'confirmation_request' => ["⚠ {$context}", $this->truncate($agentEvent->payload['message'] ?? 'Confirmation requise.')],
        };

        // Un échec d'envoi push ne doit jamais faire échouer le job agent qui
        // a émis l'événement — on log et on continue.
        try {
            $this->webPush->broadcast($title, $body, [
                'session_id' => $agentEvent->sessionId,
                'type' => $type,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function truncate(string $text, int $max = 120): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
