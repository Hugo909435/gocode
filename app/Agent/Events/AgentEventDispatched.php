<?php

namespace App\Agent\Events;

use App\Agent\AgentEvent;

class AgentEventDispatched
{
    public function __construct(
        public readonly AgentEvent $event,
    ) {}
}
