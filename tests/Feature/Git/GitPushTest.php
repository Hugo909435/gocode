<?php

namespace Tests\Feature\Git;

use App\Models\Project;
use App\Models\User;
use App\Services\GitHubService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery\MockInterface;

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
        ]);
    }

    public function test_push_passes_custom_message_to_service(): void
    {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getEncrypted')
                ->with('github.pat')
                ->andReturn('fake-token');
        });

        $this->mock(GitHubService::class, function (MockInterface $mock) {
            $mock->shouldReceive('extractGitHubPath')
                ->andReturn('owner/repo');
            
            $mock->shouldReceive('pushDirectory')
                ->with('fake-token', 'owner/repo', $this->project->path, 'main', 'My custom commit message')
                ->once();
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/{$this->project->id}/git/push", [
                'message' => 'My custom commit message'
            ]);

        $response->assertOk();
    }

    public function test_push_uses_null_message_when_not_provided(): void
    {
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getEncrypted')
                ->with('github.pat')
                ->andReturn('fake-token');
        });

        $this->mock(GitHubService::class, function (MockInterface $mock) {
            $mock->shouldReceive('extractGitHubPath')
                ->andReturn('owner/repo');
            
            $mock->shouldReceive('pushDirectory')
                ->with('fake-token', 'owner/repo', $this->project->path, 'main', null)
                ->once();
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/{$this->project->id}/git/push");

        if ($response->status() !== 200) {
            dump($response->json());
        }

        $response->assertOk();
    }
}
