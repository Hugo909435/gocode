<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Git\DiffRequest;
use App\Http\Requests\Git\LogRequest;
use App\Http\Requests\Git\PushRequest;
use App\Models\Project;
use App\Services\GitHubService;
use App\Services\GitService;
use App\Services\GitSyncService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

class GitController extends Controller
{
    public function __construct(
        private readonly GitService $git,
        private readonly GitHubService $github,
        private readonly SettingsService $settings,
        private readonly GitSyncService $sync,
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

    /**
     * Rapatrie les changements GitHub vers le dossier local (le local gagne
     * en cas de conflit). Appelé à l'ouverture d'une session côté frontend.
     */
    public function pull(Project $project): JsonResponse
    {
        try {
            $result = $this->sync->pull($project);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Pull puis push de l'état local, avec message de commit personnalisable.
     */
    public function push(PushRequest $request, Project $project): JsonResponse
    {
        try {
            $result = $this->sync->push($project, $request->input('message'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }
}
