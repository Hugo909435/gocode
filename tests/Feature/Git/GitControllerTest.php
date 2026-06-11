<?php

namespace Tests\Feature\Git;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GitControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private string $repoDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Crée un dépôt git temporaire avec un commit initial et un fichier modifié
        $this->repoDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gocode-git-'.uniqid();
        mkdir($this->repoDir, 0755, true);

        $this->gitExec(['git', 'init', '-b', 'main']);
        $this->gitExec(['git', 'config', 'user.email', 'test@gocode.test']);
        $this->gitExec(['git', 'config', 'user.name', 'GoCode Test']);

        file_put_contents($this->repoDir.'/hello.txt', "Hello world\n");
        $this->gitExec(['git', 'add', 'hello.txt']);
        $this->gitExec(['git', 'commit', '-m', 'Initial commit']);

        // Modification non commitée pour que git status / diff retournent quelque chose
        file_put_contents($this->repoDir.'/hello.txt', "Hello world\nModified line\n");

        $this->project = Project::factory()->create(['path' => $this->repoDir]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Supprime le dépôt temporaire
        if (is_dir($this->repoDir)) {
            $this->removeDir($this->repoDir);
        }
    }

    // ─── Auth ────────────────────────────────────────────────────────────────

    public function test_git_endpoints_require_authentication(): void
    {
        $this->getJson("/api/projects/{$this->project->id}/git/status")->assertUnauthorized();
        $this->getJson("/api/projects/{$this->project->id}/git/diff")->assertUnauthorized();
        $this->getJson("/api/projects/{$this->project->id}/git/branch")->assertUnauthorized();
        $this->getJson("/api/projects/{$this->project->id}/git/log")->assertUnauthorized();
    }

    // ─── /git/status ─────────────────────────────────────────────────────────

    public function test_status_returns_modified_files(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/status");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['files', 'clean']])
            ->assertJsonPath('data.clean', false);

        $files = $response->json('data.files');
        $this->assertNotEmpty($files);
        $this->assertSame('hello.txt', $files[0]['path']);
        $this->assertContains($files[0]['status'], ['modified', 'added', 'untracked']);
    }

    public function test_status_returns_clean_on_unmodified_repo(): void
    {
        // Committe la modification pour remettre le dépôt à l'état propre
        $this->gitExec(['git', 'add', '.']);
        $this->gitExec(['git', 'commit', '-m', 'Second commit']);

        $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/status")
            ->assertOk()
            ->assertJsonPath('data.clean', true)
            ->assertJsonPath('data.files', []);
    }

    public function test_status_returns_422_when_path_is_not_a_git_repo(): void
    {
        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'not-a-repo-'.uniqid();
        mkdir($tmpDir, 0755, true);
        $project = Project::factory()->create(['path' => $tmpDir]);

        $this->actingAs($this->user)
            ->getJson("/api/projects/{$project->id}/git/status")
            ->assertUnprocessable()
            ->assertJsonStructure(['message']);

        rmdir($tmpDir);
    }

    public function test_status_returns_422_when_path_does_not_exist(): void
    {
        $project = Project::factory()->create(['path' => '/no/such/path']);

        $this->actingAs($this->user)
            ->getJson("/api/projects/{$project->id}/git/status")
            ->assertUnprocessable()
            ->assertJsonStructure(['message']);
    }

    // ─── /git/diff ───────────────────────────────────────────────────────────

    public function test_diff_returns_unified_diff(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/diff");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['diff']]);

        $diff = $response->json('data.diff');
        $this->assertStringContainsString('hello.txt', $diff);
        $this->assertStringContainsString('+Modified line', $diff);
    }

    public function test_diff_scoped_to_specific_file(): void
    {
        // Crée un second fichier modifié
        file_put_contents($this->repoDir.'/other.txt', "other\n");
        $this->gitExec(['git', 'add', 'other.txt']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/diff?file=hello.txt");

        $response->assertOk();
        $diff = $response->json('data.diff');
        $this->assertStringContainsString('hello.txt', $diff);
        $this->assertStringNotContainsString('other.txt', $diff);
    }

    public function test_diff_rejects_path_traversal_in_file_param(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/diff?file=../../etc/passwd")
            ->assertUnprocessable();
    }

    public function test_diff_rejects_absolute_path_in_file_param(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/diff?file=/etc/passwd")
            ->assertUnprocessable();
    }

    // ─── /git/branch ─────────────────────────────────────────────────────────

    public function test_branch_returns_current_branch_name(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/branch")
            ->assertOk()
            ->assertJsonStructure(['data' => ['branch']])
            ->assertJsonPath('data.branch', 'main');
    }

    // ─── /git/log ────────────────────────────────────────────────────────────

    public function test_log_returns_commits(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/log");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['commits']]);

        $commits = $response->json('data.commits');
        $this->assertCount(1, $commits);
        $this->assertArrayHasKey('hash', $commits[0]);
        $this->assertArrayHasKey('short_hash', $commits[0]);
        $this->assertArrayHasKey('message', $commits[0]);
        $this->assertArrayHasKey('author', $commits[0]);
        $this->assertArrayHasKey('email', $commits[0]);
        $this->assertArrayHasKey('date', $commits[0]);
        $this->assertSame('Initial commit', $commits[0]['message']);
    }

    public function test_log_respects_limit_parameter(): void
    {
        // Ajoute 5 commits supplémentaires
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($this->repoDir."/file{$i}.txt", "content {$i}\n");
            $this->gitExec(['git', 'add', "file{$i}.txt"]);
            $this->gitExec(['git', 'commit', '-m', "Commit {$i}"]);
        }

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/log?limit=3");

        $response->assertOk();
        $this->assertCount(3, $response->json('data.commits'));
    }

    public function test_log_returns_422_for_invalid_limit(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/git/log?limit=0")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function gitExec(array $command): void
    {
        $process = new Process($command, $this->repoDir);
        $process->mustRun();
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                // Les fichiers objets git sont read-only sous Windows
                @chmod($path, 0777);
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
