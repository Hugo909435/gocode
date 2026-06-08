<?php

namespace App\Services\Agent;

use App\Contracts\AgentDriverContract;
use App\Models\Session;
use Illuminate\Support\Facades\Log;

/**
 * Pilote factice pour le développement local.
 * Simule les événements d'un agent sans appel réseau.
 */
class MockDriver implements AgentDriverContract
{
    public function startSession(Session $session): void
    {
        Log::info('[MockDriver] startSession', ['session_id' => $session->id]);
    }

    public function sendInstruction(Session $session, string $instruction, string $mode): void
    {
        Log::info('[MockDriver] sendInstruction', [
            'session_id'  => $session->id,
            'mode'        => $mode,
            'instruction' => $instruction,
        ]);

        // TODO : émettre de vrais événements SSE via un job ou un stream
    }

    public function stop(Session $session): void
    {
        Log::info('[MockDriver] stop', ['session_id' => $session->id]);
    }
}
