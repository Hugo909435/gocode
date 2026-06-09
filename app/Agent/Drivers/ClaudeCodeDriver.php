<?php

namespace App\Agent\Drivers;

use App\Agent\AgentEvent;
use App\Agent\AgentEventDispatcher;
use App\Agent\AgentEventType;
use App\Contracts\AgentDriverContract;
use App\Jobs\ClaudeCodeJob;
use App\Models\Session;
use Illuminate\Support\Facades\Cache;

/**
 * Pilote qui exécute Claude Code CLI comme sous-processus.
 *
 * Claude Code tourne en mode non-interactif (--print) avec --output-format stream-json.
 * Le job ClaudeCodeJob gère le cycle de vie du processus et traduit les événements
 * stream-json en AgentEvents persistés via AgentEventDispatcher.
 *
 * Modes :
 *   read    → --allowedTools Read,Glob,Grep,LS,TodoRead  (lecture seule, pas de permissions)
 *   plan    → idem + appendSystemPrompt demandant un plan sans modification
 *   execute → --dangerously-skip-permissions (exécution complète, headless)
 *
 * Confirmations git : non implémentées en V1 (besoin de pausing process — V2).
 * Pour l'instant, execute-mode tourne avec dangerously-skip-permissions.
 */
class ClaudeCodeDriver implements AgentDriverContract
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
        ClaudeCodeJob::dispatch($session->id, $instruction, $mode);
    }

    /**
     * En V1, ClaudeCodeJob ne pause pas le processus — confirmAction ne reprend
     * pas un job suspendu mais pourrait être utilisé pour pré-confirmer une action.
     * Laissé vide pour satisfaire le contrat (le MockDriver l'utilise, pas Claude Code V1).
     */
    public function confirmAction(Session $session, string $actionId, bool $approved): void
    {
        if (! $approved) {
            $session->update(['status' => 'idle']);
            $this->dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Message,
                $session->id,
                ['text' => 'Action annulée par l\'utilisateur.'],
            ));
        }
    }

    public function stop(Session $session): void
    {
        Cache::put("claude-code.stop.{$session->id}", true, 60);

        $session->update(['status' => 'idle', 'ended_at' => now()]);

        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $session->id,
            ['status' => 'idle', 'message' => 'Session arrêtée par l\'utilisateur.'],
        ));
    }
}
