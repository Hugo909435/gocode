<?php

namespace App\Agent;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class AgentEvent
{
    public function __construct(
        public AgentEventType $type,
        public string $sessionId,
        public DateTimeImmutable $timestamp,
        public array $payload,
    ) {}

    public static function make(AgentEventType $type, string $sessionId, array $payload = []): self
    {
        return new self($type, $sessionId, new DateTimeImmutable, $payload);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'session_id' => $this->sessionId,
            'timestamp' => $this->timestamp->format(DateTimeInterface::ATOM),
            'payload' => $this->payload,
        ];
    }
}
