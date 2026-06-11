<?php

namespace App\Agent;

use App\Agent\Events\AgentEventDispatched;
use App\Models\Message;
use DateTimeInterface;

class AgentEventDispatcher
{
    /**
     * Persiste l'événement en base puis le publie localement.
     *
     * Choix architectural : la persistance dans `messages` constitue le canal
     * de diffusion pour l'endpoint SSE, qui effectue un polling DB avec curseur
     * (WHERE id > last_id). Simple, sans Redis, suffisant pour un usage mono-utilisateur.
     */
    public function dispatch(AgentEvent $event): void
    {
        Message::create([
            'session_id' => $event->sessionId,
            'role' => $this->resolveRole($event->type),
            'type' => $this->resolveMessageType($event->type),
            'content' => json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta' => [
                'event_type' => $event->type->value,
                'timestamp' => $event->timestamp->format(DateTimeInterface::ATOM),
            ],
        ]);

        event(new AgentEventDispatched($event));
    }

    private function resolveRole(AgentEventType $type): string
    {
        return match ($type) {
            AgentEventType::Message, AgentEventType::Plan => 'agent',
            AgentEventType::ToolCall,
            AgentEventType::Terminal,
            AgentEventType::FileChange => 'tool',
            default => 'system',
        };
    }

    /**
     * Traduit l'AgentEventType vers l'enum `type` de la table `messages`.
     * `message` → `text` (nom DB), `done` → `status` (pas de colonne dédiée).
     */
    private function resolveMessageType(AgentEventType $type): string
    {
        return match ($type) {
            AgentEventType::Message => 'text',
            AgentEventType::Done => 'status',
            default => $type->value,
        };
    }
}
