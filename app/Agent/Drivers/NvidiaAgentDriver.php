<?php

namespace App\Agent\Drivers;

use App\Agent\AgentEvent;
use App\Agent\AgentEventDispatcher;
use App\Agent\AgentEventType;
use App\Contracts\AgentDriverContract;
use App\Jobs\NvidiaAgentJob;
use App\Jobs\NvidiaCollabJob;
use App\Models\Session;
use Illuminate\Support\Facades\Cache;

/**
 * Pilote qui appelle l'API NVIDIA integrate.api.nvidia.com (Kimi K2.6 et
 * DeepSeek) via function-calling. Remplace ClaudeCodeDriver.
 *
 * Modes :
 *   read / plan → NvidiaCollabJob : les deux modèles explorent le code en
 *                 lecture seule et leurs réponses sont fusionnées.
 *   execute     → NvidiaAgentJob : un seul modèle (session.model, "kimi" par
 *                 défaut) exécute la tâche avec accès en écriture + bash.
 *
 * Confirmations : `git commit`/`git push` déclenchent un événement
 * confirmation_request et suspendent le job (état sauvegardé en cache) —
 * voir NvidiaAgentJob::pauseForConfirmation.
 */
class NvidiaAgentDriver implements AgentDriverContract
{
    public function __construct(
        private readonly AgentEventDispatcher $dispatcher,
    ) {}

    public function startSession(Session $session): void
    {
        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $session->id,
            ['status' => 'idle', 'message' => 'Session prête. Entrez une instruction pour démarrer.'],
        ));
    }

    public function sendInstruction(Session $session, string $instruction, string $mode): void
    {
        if ($mode === 'execute') {
            $modelKey = $session->model ?? config('agent.drivers.nvidia.default_model', 'kimi');

            NvidiaAgentJob::dispatch($session->id, $instruction, $mode, $modelKey);

            return;
        }

        NvidiaCollabJob::dispatch($session->id, $instruction, $mode);
    }

    public function confirmAction(Session $session, string $actionId, bool $approved): void
    {
        $cacheKey = "nvidia.pending.{$session->id}";
        $pending = Cache::get($cacheKey);

        if (! $pending || $pending['action_id'] !== $actionId) {
            return;
        }

        if (! $approved) {
            Cache::forget($cacheKey);
            $session->update(['status' => 'idle']);
            $this->dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Message,
                $session->id,
                ['text' => 'Action annulée par l\'utilisateur.'],
            ));

            return;
        }

        NvidiaAgentJob::dispatch(
            sessionId: $session->id,
            instruction: '',
            mode: $pending['mode'],
            modelKey: $pending['model_key'],
            resumed: true,
            actionId: $actionId,
        );
    }

    public function stop(Session $session): void
    {
        Cache::forget("nvidia.pending.{$session->id}");
        Cache::put("nvidia.stop.{$session->id}", true, 60);

        $session->update(['status' => 'idle', 'ended_at' => now()]);

        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $session->id,
            ['status' => 'idle', 'message' => 'Session arrêtée par l\'utilisateur.'],
        ));
    }
}
