<?php

namespace App\Services;

use App\Contracts\AgentDriverContract;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use Illuminate\Validation\ValidationException;

class SessionService
{
    /** Statuts pendant lesquels une nouvelle instruction est refusée */
    private const ACTIVE_STATUSES = ['reading', 'planning', 'building', 'running'];

    public function __construct(
        private readonly AgentDriverContract $driver,
    ) {}

    /**
     * Crée une session, la lie au projet, puis démarre le driver.
     */
    public function create(Project $project, array $data): Session
    {
        $session = $project->sessions()->create([
            'title' => $data['title'] ?? null,
            'mode' => $data['mode'] ?? 'read',
            'initial_instruction' => $data['initial_instruction'] ?? null,
            'status' => 'idle',
        ]);

        $this->driver->startSession($session);

        return $session;
    }

    /**
     * Persiste le message utilisateur puis délègue au driver.
     *
     * Le mode passé en paramètre peut différer du mode courant de la session
     * (ex. l'utilisateur change de mode pour une instruction ponctuelle).
     * On met à jour la session si le mode est explicitement fourni.
     */
    public function sendInstruction(Session $session, string $instruction, ?string $mode): Message
    {
        // Anti-chevauchement : un seul run agent à la fois par session (un
        // double-tap mobile lancerait deux processus Claude Code concurrents).
        // Complété par WithoutOverlapping sur ClaudeCodeJob pour la fenêtre
        // entre le dispatch et le passage du statut à « reading ».
        if (in_array($session->status, self::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'instruction' => 'Une tâche est déjà en cours sur cette session. Attendez la fin ou arrêtez-la.',
            ]);
        }

        $effectiveMode = $mode ?? $session->mode;

        if ($mode !== null && $mode !== $session->mode) {
            $session->update(['mode' => $mode]);
        }

        if ($session->started_at === null) {
            $session->update(['started_at' => now()]);
        }

        // Le message utilisateur doit être persisté avant d'appeler le driver
        $message = $session->messages()->create([
            'role' => 'user',
            'type' => 'text',
            'content' => $instruction,
        ]);

        $this->driver->sendInstruction($session, $instruction, $effectiveMode);

        return $message;
    }

    public function confirmAction(Session $session, string $actionId, bool $approved): void
    {
        $this->driver->confirmAction($session, $actionId, $approved);
    }

    public function stop(Session $session): void
    {
        $this->driver->stop($session);
    }

    public function update(Session $session, array $data): Session
    {
        if (! empty($data)) {
            $session->update($data);
        }

        return $session;
    }
}
