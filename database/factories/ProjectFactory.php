<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(fake()->numberBetween(1, 3), true);

        return [
            'name'           => ucwords($name),
            'path'           => '/srv/projects/' . str_replace(' ', '-', strtolower($name)),
            'default_branch' => fake()->randomElement(['main', 'master', 'develop']),
            'stack'          => fake()->optional(0.8)->randomElement([
                'Laravel/Vue', 'Next.js', 'Django/React', 'Rails/Hotwire', 'Spring/Angular',
                'Express/React', 'FastAPI/Svelte', 'Phoenix/LiveView',
            ]),
            'description'    => fake()->optional(0.6)->sentence(),
            'git_remote'     => fake()->optional(0.7)->regexify('https://github\.com/[a-z]{5,10}/[a-z-]{5,15}\.git'),
            'metadata'       => null,
        ];
    }
}
