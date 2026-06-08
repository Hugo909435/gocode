<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Session;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
{
    public function definition(): array
    {
        $status = fake()->randomElement(['idle', 'reading', 'planning', 'awaiting_confirmation', 'building', 'running', 'done', 'error']);
        $startedAt = in_array($status, ['idle']) ? null : fake()->dateTimeBetween('-30 days', '-1 hour');
        $endedAt = in_array($status, ['done', 'error']) ? fake()->dateTimeBetween($startedAt ?? '-1 hour', 'now') : null;

        return [
            'project_id'          => Project::factory(),
            'title'               => fake()->sentence(fake()->numberBetween(3, 7)),
            'mode'                => fake()->randomElement(['read', 'plan', 'execute']),
            'status'              => $status,
            'initial_instruction' => fake()->paragraph(),
            'input_tokens'        => fake()->numberBetween(0, 50_000),
            'output_tokens'       => fake()->numberBetween(0, 20_000),
            'cost_usd'            => fake()->randomFloat(6, 0, 2),
            'started_at'          => $startedAt,
            'ended_at'            => $endedAt,
        ];
    }

    public function idle(): static
    {
        return $this->state(['status' => 'idle', 'started_at' => null, 'ended_at' => null]);
    }

    public function running(): static
    {
        return $this->state(['status' => 'running', 'started_at' => now(), 'ended_at' => null]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status'     => 'done',
            'started_at' => fake()->dateTimeBetween('-30 days', '-1 hour'),
            'ended_at'   => now(),
        ]);
    }
}
