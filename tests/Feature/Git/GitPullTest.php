<?php

namespace Tests\Feature\Git;

use App\Models\Project;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Teste les règles de synchronisation pull (stratégie « le local gagne »)
 * contre une API GitHub simulée par Http::fake.
 */
class GitPullTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private string $localPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->localPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gocode_pull_'.uniqid();
        mkdir($this->localPath, 0777, true);

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getEncrypted')->with('github.pat')->andReturn('fake-token');
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->localPath);
        parent::tearDown();
    }

    private function blobSha(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }

    private function writeLocal(string $relative, string $content): void
    {
        file_put_contents($this->localPath.DIRECTORY_SEPARATOR.$relative, $content);
    }

    private function localContent(string $relative): ?string
    {
        $path = $this->localPath.DIRECTORY_SEPARATOR.$relative;

        return is_file($path) ? file_get_contents($path) : null;
    }

    /**
     * Simule l'API GitHub : une branche main dont le tree contient $remoteFiles.
     *
     * @param  array<string, string>  $remoteFiles  path => contenu
     */
    private function fakeGitHub(array $remoteFiles): void
    {
        $headSha = str_repeat('a', 40);
        $treeSha = str_repeat('b', 40);

        $treeEntries = [];
        $blobs = [];
        foreach ($remoteFiles as $path => $content) {
            $sha = $this->blobSha($content);
            $treeEntries[] = ['path' => $path, 'type' => 'blob', 'sha' => $sha];
            $blobs[$sha] = $content;
        }

        Http::fake(function ($request) use ($headSha, $treeSha, $treeEntries, $blobs) {
            $url = $request->url();

            if (str_contains($url, '/git/refs/heads/main')) {
                return Http::response(['object' => ['sha' => $headSha]]);
            }

            if (str_contains($url, "/git/commits/{$headSha}")) {
                return Http::response(['tree' => ['sha' => $treeSha]]);
            }

            if (str_contains($url, "/git/trees/{$treeSha}")) {
                return Http::response(['tree' => $treeEntries]);
            }

            if (preg_match('#/git/blobs/([0-9a-f]{40})$#', $url, $m) && isset($blobs[$m[1]])) {
                return Http::response(['content' => base64_encode($blobs[$m[1]]), 'encoding' => 'base64']);
            }

            return Http::response(['message' => 'Not Found'], 404);
        });
    }

    private function makeProject(array $baseTree): Project
    {
        return Project::factory()->create([
            'git_remote' => 'https://github.com/owner/repo',
            'path' => $this->localPath,
            'default_branch' => 'main',
            'metadata' => ['git_sync' => ['branch' => 'main', 'head_sha' => 'old', 'tree' => $baseTree]],
        ]);
    }

    public function test_pull_applies_remote_changes_and_keeps_local_modifications(): void
    {
        // État au dernier sync (ancêtre commun)
        $baseTree = [
            'clean.txt' => $this->blobSha('v1'),
            'modified-local.txt' => $this->blobSha('v1'),
            'gone-remote.txt' => $this->blobSha('v1'),
        ];

        // État local : clean.txt intact, modified-local.txt édité par Claude,
        // gone-remote.txt intact
        $this->writeLocal('clean.txt', 'v1');
        $this->writeLocal('modified-local.txt', 'local-edit');
        $this->writeLocal('gone-remote.txt', 'v1');

        // État distant : clean.txt et modified-local.txt modifiés sur GitHub,
        // new-remote.txt créé, gone-remote.txt supprimé
        $this->fakeGitHub([
            'clean.txt' => 'v2-remote',
            'modified-local.txt' => 'v2-remote',
            'new-remote.txt' => 'fresh',
        ]);

        $project = $this->makeProject($baseTree);

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/{$project->id}/git/pull");

        $response->assertOk()
            ->assertJsonPath('data.status', 'ok');

        $data = $response->json('data');

        // Fichier propre → mis à jour depuis GitHub
        $this->assertContains('clean.txt', $data['updated']);
        $this->assertSame('v2-remote', $this->localContent('clean.txt'));

        // Nouveau fichier distant → créé localement
        $this->assertContains('new-remote.txt', $data['updated']);
        $this->assertSame('fresh', $this->localContent('new-remote.txt'));

        // Conflit → le local gagne, fichier intact
        $this->assertContains('modified-local.txt', $data['conflicts']);
        $this->assertSame('local-edit', $this->localContent('modified-local.txt'));

        // Supprimé côté GitHub et propre localement → supprimé localement
        $this->assertContains('gone-remote.txt', $data['deleted']);
        $this->assertNull($this->localContent('gone-remote.txt'));

        // La base stockée garde le fichier en conflit comme divergent
        $project->refresh();
        $this->assertSame(
            $this->blobSha('v1'),
            $project->metadata['git_sync']['tree']['modified-local.txt'],
        );
    }

    public function test_pull_is_noop_when_remote_branch_does_not_exist(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $project = $this->makeProject([]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/{$project->id}/git/pull");

        $response->assertOk()
            ->assertJsonPath('data.status', 'empty_remote');
    }

    public function test_pull_fails_cleanly_without_remote(): void
    {
        $project = Project::factory()->create(['git_remote' => null, 'path' => $this->localPath]);

        $this->actingAs($this->user)
            ->postJson("/api/projects/{$project->id}/git/pull")
            ->assertStatus(422);
    }
}
