<?php

namespace Tests\Feature\Git;

use App\Models\Project;
use App\Models\User;
use App\Services\GitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class GitPushTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create([
            'git_remote' => 'https://github.com/owner/repo',
            'path' => sys_get_temp_dir(),
            'default_branch' => 'main',
        ]);
    }

    private function syncResult(): array
    {
        return [
            'branch' => 'main',
            'remote' => 'https://github.com/owner/repo',
            'status' => 'pushed',
            'pushed' => 2,
            'deleted' => 0,
            'pull' => ['updated' => [], 'deleted' => [], 'conflicts' => []],
        ];
    }

    public function test_push_passes_custom_message_to_service(): void
    {
        $this->mock(GitSyncService::class, function (MockInterface $mock) {
            $mock->shouldReceive('push')
                ->once()
                ->withArgs(fn (Project $project, ?string $message) => $project->is($this->project)
                    && $message === 'My custom commit message')
                ->andReturn($this->syncResult());
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/{$this->project->id}/git/push", [
                'message' => 'My custom commit message',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.branch', 'main')
            ->assertJsonPath('data.pushed', 2);
    }

    public function test_push_uses_null_message_when_not_provided(): void
    {
        $this->mock(GitSyncService::class, function (MockInterface $mock) {
            $mock->shouldReceive('push')
                ->once()
                ->withArgs(fn (Project $project, ?string $message) => $project->is($this->project)
                    && $message === null)
                ->andReturn($this->syncResult());
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/{$this->project->id}/git/push");

        $response->assertOk();
    }

    public function test_push_fails_cleanly_without_remote(): void
    {
        $this->project->update(['git_remote' => null]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/{$this->project->id}/git/push");

        $response->assertStatus(422);
    }
}
