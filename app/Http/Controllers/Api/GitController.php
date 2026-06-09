<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Git\DiffRequest;
use App\Http\Requests\Git\LogRequest;
use App\Http\Requests\Git\PushRequest;
use App\Models\Project;
use App\Services\GitHubService;
use App\Services\GitService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

class GitController extends Controller
{
    public function __construct(
        private readonly GitService $git,
        private readonly GitHubService $github,
        private readonly SettingsService $settings,
    ) {}

    public function status(Project $project): JsonResponse
    {
        try {
            $result = $this->git->status($project);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function diff(DiffRequest $request, Project $project): JsonResponse
    {
        try {
            $diff = $this->git->diff($project, $request->input('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['diff' => $diff]]);
    }

    public function branch(Project $project): JsonResponse
    {
        try {
            $branch = $this->git->currentBranch($project);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['branch' => $branch]]);
    }

    public function log(LogRequest $request, Project $project): JsonResponse
    {
        try {
            $commits = $this->git->log($project, (int) $request->input('limit', 20));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['commits' => $commits]]);
    }

    public function push(PushRequest $request, Project $project): JsonResponse
    {
        if (! $project->git_remote) {
            return response()->json(['message' => 'Ce projet n\'a pas de remote GitHub configuré.'], 422);
        }

        $token = $this->settings->getEncrypted('github.pat');

        if (! $token) {
            return response()->json(['message' => 'Aucun token GitHub configuré dans les paramètres.'], 422);
        }

        $path   = $project->path;
        $branch = $project->default_branch ?: 'main';
        $remote = $project->git_remote;

        if (! $path || ! is_dir($path)) {
            return response()->json(['message' => "Chemin du projet invalide : {$path}"], 422);
        }

        $authenticatedUrl = preg_replace('#^(https://)#', "https://{$token}@", $remote);

        // Extraire owner/repo depuis l'URL remote
        $repoPath = $this->github->extractGitHubPath($remote);

        if (! $repoPath) {
            return response()->json(['message' => "URL GitHub non reconnue : {$remote}"], 422);
        }

        try {
            $this->github->pushDirectory($token, $repoPath, $path, $branch, $request->input('message'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['branch' => $branch, 'remote' => $remote]]);
    }

}
