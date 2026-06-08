<?php

namespace App\Agent;

use App\Agent\Drivers\MockAgentDriver;
use App\Contracts\AgentDriverContract;
use InvalidArgumentException;

class AgentManager
{
    /** @var array<string, AgentDriverContract> */
    protected array $resolved = [];

    public function driver(?string $name = null): AgentDriverContract
    {
        $name ??= config('agent.default', 'mock');

        return $this->resolved[$name] ??= $this->createDriver($name);
    }

    protected function createDriver(string $name): AgentDriverContract
    {
        return match ($name) {
            'mock'  => new MockAgentDriver(
                dispatcher: app(AgentEventDispatcher::class),
                scenario: config('agent.drivers.mock.scenario', 'success'),
                delayMs: config('agent.drivers.mock.delay_ms', 1000),
            ),
            default => throw new InvalidArgumentException("Agent driver [{$name}] is not supported."),
        };
    }
}
