<?php

namespace App\Contracts;

use App\Models\Session;

interface AgentDriverContract
{
    /**
     * Démarre une nouvelle session d'agent pour le projet associé.
     */
    public function startSession(Session $session): void;

    /**
     * Envoie une instruction à l'agent dans le contexte d'une session.
     *
     * @param  string  $mode  'read' | 'plan' | 'execute'
     */
    public function sendInstruction(Session $session, string $instruction, string $mode): void;

    /**
     * Arrête proprement l'agent pour la session donnée.
     */
    public function stop(Session $session): void;
}
