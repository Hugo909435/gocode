<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Git\DiffRequest;
use App\Http\Requests\Git\LogRequest;
use App\Models\Project;
use App\Services\GitService;
use Illuminate\Http\JsonResponse;

class GitController extends Controller
{
    public function __construct(
        private readonly GitService $git,
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
}
