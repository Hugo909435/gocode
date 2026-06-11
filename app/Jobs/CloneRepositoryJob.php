<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\GitHubService;
use App\Services\GitService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CloneRepositoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public readonly int $projectId,
        public readonly string $repoUrl,
    ) {}

    public function handle(GitService $git, GitHubService $github, SettingsService $settings): void
    {
        $project = Project::findOrFail($this->projectId);

        $project->update(['clone_status' => 'cloning', 'clone_error' => null]);

        try {
            $pat = $settings->getEncrypted('github.pat');

            if (! $pat) {
                throw new \RuntimeException('PAT GitHub non configuré.');
            }

            $authenticatedUrl = $github->buildAuthenticatedUrl($this->repoUrl, $pat);
            $localPath = $github->getClonePath($this->projectId);

            $git->cloneRepo($authenticatedUrl, $localPath);

            $project->update([
                'path' => $localPath,
                'clone_status' => 'cloned',
                'clone_error' => null,
            ]);
        } catch (\Exception $e) {
            $project->update([
                'clone_status' => 'error',
                'clone_error' => $e->getMessage(),
            ]);
        }
    }
}
