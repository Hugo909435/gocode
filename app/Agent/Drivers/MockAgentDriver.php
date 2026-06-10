<?php

namespace App\Agent\Drivers;

use App\Agent\AgentEvent;
use App\Agent\AgentEventDispatcher;
use App\Agent\AgentEventType;
use App\Contracts\AgentDriverContract;
use App\Jobs\MockAgentJob;
use App\Models\Session;
use Illuminate\Support\Facades\Cache;

/**
 * Pilote factice pour le développement local.
 *
 * Délègue toute la simulation à MockAgentJob qui tourne en file d'attente
 * (QUEUE_CONNECTION=database + `php artisan queue:work`) avec des usleep() entre
 * chaque événement — ce qui permet à l'endpoint SSE de les délivrer progressivement.
 *
 * Scénarios disponibles (configurables via config('agent.drivers.mock.scenario')) :
 *   - 'success' : parcours complet selon le mode (read / plan / execute)
 *   - 'error'   : émet un événement error après la phase reading
 *
 * @see MockAgentJob pour la séquence détaillée d'événements.
 */
class MockAgentDriver implements AgentDriverContract
{
    public function __construct(
        private readonly AgentEventDispatcher $dispatcher,
        private readonly string $scenario = 'success',
        private readonly int $delayMs = 1000,
    ) {}

    public function startSession(Session $session): void
    {
        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $session->id,
            ['status' => 'idle', 'message' => 'Session démarrée.'],
        ));
    }

    /**
     * Dispatch le job asynchrone qui simulera la séquence d'événements.
     * Avec QUEUE_CONNECTION=sync le job s'exécute immédiatement (bloquant).
     * Avec QUEUE_CONNECTION=database le job part en arrière-plan.
     */
    public function sendInstruction(Session $session, string $instruction, string $mode, array $skills = []): void
    {
        MockAgentJob::dispatch(
            sessionId: $session->id,
            instruction: $instruction,
            mode: $mode,
            scenario: $this->scenario,
            resumed: false,
            actionId: '',
            delayMs: $this->delayMs,
        );
    }

    /**
     * Reprend la séquence après qu'un utilisateur a répondu à une confirmation.
     *
     * Le job initial a stocké l'action_id dans le cache avant de s'arrêter.
     * Si l'ID correspond, on dispatch le job de reprise (resumed=true).
     * Si l'action est refusée, on émet un message d'annulation.
     */
    public function confirmAction(Session $session, string $actionId, bool $approved): void
    {
        $cacheKey = "mock.pending.{$session->id}";
        $pending  = Cache::get($cacheKey);

        if (! $pending || $pending['action_id'] !== $actionId) {
            return;
        }

        Cache::forget($cacheKey);

        if (! $approved) {
            $session->update(['status' => 'idle']);
            $this->dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Message,
                $session->id,
                ['text' => 'Action annulée par l\'utilisateur.'],
            ));

            return;
        }

        MockAgentJob::dispatch(
            sessionId: $session->id,
            instruction: '',
            mode: $session->mode,
            scenario: $this->scenario,
            resumed: true,
            actionId: $actionId,
            delayMs: $this->delayMs,
        );
    }

    public function stop(Session $session): void
    {
        Cache::forget("mock.pending.{$session->id}");

        $session->update(['status' => 'idle', 'ended_at' => now()]);

        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $session->id,
            ['status' => 'idle', 'message' => 'Session arrêtée par l\'utilisateur.'],
        ));
    }
}
