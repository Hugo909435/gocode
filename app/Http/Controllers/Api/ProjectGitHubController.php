<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\LinkGitHubRequest;
use App\Http\Resources\ProjectResource;
use App\Jobs\CloneRepositoryJob;
use App\Models\Project;
use App\Services\GitHubService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ProjectGitHubController extends Controller
{
    public function __construct(
        private readonly GitHubService $github,
        private readonly SettingsService $settings,
    ) {}

    public function link(LinkGitHubRequest $request, Project $project): JsonResponse
    {
        $pat = $this->settings->getEncrypted('github.pat');

        if (! $pat) {
            return response()->json([
                'message' => 'PAT GitHub non configuré. Rendez-vous dans Paramètres.',
            ], 422);
        }

        $repoUrl = $request->input('repo_url');

        $project->update([
            'git_remote'   => $repoUrl,
            'clone_status' => 'pending',
            'clone_error'  => null,
        ]);

        CloneRepositoryJob::dispatch($project->id, $repoUrl);

        return response()->json(['data' => new ProjectResource($project->fresh())]);
    }

    public function createRepo(Project $project): JsonResponse
    {
        $pat = $this->settings->getEncrypted('github.pat');

        if (! $pat) {
            return response()->json(['message' => 'PAT GitHub non configuré. Rendez-vous dans Paramètres.'], 422);
        }

        $name        = request()->input('name', $project->name);
        $private     = (bool) request()->input('private', true);
        $description = request()->input('description', $project->description);

        try {
            $repo = $this->github->createRepo($pat, $name, $private, $description);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $project->update(['git_remote' => $repo['clone_url']]);

        return response()->json([
            'data' => new ProjectResource($project->fresh()),
            'repo' => $repo,
        ]);
    }

    public function unlink(Project $project): JsonResponse
    {
        $clonePath = $this->github->getClonePath($project->id);

        // Suppression du clone local s'il existe
        if (is_dir($clonePath)) {
            $this->deleteDirectory($clonePath);
        }

        $project->update([
            'git_remote'   => null,
            'clone_status' => null,
            'clone_error'  => null,
            // On réinitialise le path uniquement s'il pointait vers notre clone géré
            'path'         => str_starts_with($project->path ?? '', storage_path('app/repos'))
                ? null
                : $project->path,
        ]);

        return response()->json(['data' => new ProjectResource($project->fresh())]);
    }

    private function deleteDirectory(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('rmdir /S /Q ' . escapeshellarg($path));
        } else {
            exec('rm -rf ' . escapeshellarg($path));
        }
    }
}
