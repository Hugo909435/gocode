<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\Session;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(['text', 'plan', 'log', 'terminal', 'tool_call', 'file_change', 'status', 'error']);
        $role = match ($type) {
            'text' => fake()->randomElement(['user', 'agent']),
            'plan' => 'agent',
            'log', 'terminal' => 'system',
            'tool_call' => 'tool',
            'file_change' => 'tool',
            'status', 'error' => 'system',
            default => 'agent',
        };

        return [
            'session_id' => Session::factory(),
            'role' => $role,
            'type' => $type,
            'content' => $this->generateContent($type),
            'meta' => null,
        ];
    }

    public function userMessage(): static
    {
        return $this->state([
            'role' => 'user',
            'type' => 'text',
            'content' => fake()->paragraph(),
        ]);
    }

    public function agentMessage(): static
    {
        return $this->state([
            'role' => 'agent',
            'type' => 'text',
            'content' => fake()->paragraphs(fake()->numberBetween(1, 3), true),
        ]);
    }

    public function fileChange(): static
    {
        $file = 'app/'.fake()->word().'/'.ucfirst(fake()->word()).'.php';

        return $this->state([
            'role' => 'tool',
            'type' => 'file_change',
            'content' => "@@ -1,5 +1,8 @@\n <?php\n\n-// old line\n+// new line\n+// another line\n namespace App;",
            'meta' => ['file' => $file, 'additions' => 2, 'deletions' => 1],
        ]);
    }

    private function generateContent(string $type): string
    {
        return match ($type) {
            'text' => fake()->paragraphs(fake()->numberBetween(1, 2), true),
            'plan' => implode("\n", array_map(
                fn ($i) => "- Step {$i}: ".fake()->sentence(),
                range(1, fake()->numberBetween(3, 6))
            )),
            'log' => '['.now()->toIso8601String().'] '.fake()->sentence(),
            'terminal' => '$ '.fake()->randomElement(['ls -la', 'php artisan migrate', 'npm run build'])."\n".fake()->sentence(),
            'tool_call' => json_encode(['tool' => fake()->word(), 'args' => ['path' => fake()->filePath()]]),
            'file_change' => "@@ -1,3 +1,4 @@\n ".fake()->word()."\n+".fake()->word()."\n-".fake()->word(),
            'status' => fake()->randomElement(['thinking', 'working', 'idle']),
            'error' => fake()->sentence(),
            default => fake()->sentence(),
        };
    }
}
