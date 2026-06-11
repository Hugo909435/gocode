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
     * Répond à une demande de confirmation émise par l'agent.
     *
     * @param  string  $actionId  Identifiant de l'action (payload du confirmation_request)
     * @param  bool  $approved  true = confirmer, false = annuler
     */
    public function confirmAction(Session $session, string $actionId, bool $approved): void;

    /**
     * Arrête proprement l'agent pour la session donnée.
     */
    public function stop(Session $session): void;
}
