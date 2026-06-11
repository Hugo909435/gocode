<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Process\Process;

class ProjectController extends Controller
{
    public function index(): JsonResponse
    {
        $projects = Project::orderByDesc('created_at')->get();

        return response()->json(['data' => ProjectResource::collection($projects)]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $validated = $request->validated();
        unset($validated['git_init']);

        $path = $validated['path'] ?? null;

        if ($path) {
            if (! is_dir($path)) {
                if (! mkdir($path, 0755, true)) {
                    return response()->json(['message' => "Impossible de créer le dossier : {$path}"], 422);
                }
            }

            $check = new Process(['git', 'rev-parse', '--git-dir'], $path);
            $check->run();

            if (! $check->isSuccessful()) {
                $init = new Process(['git', 'init'], $path);
                $init->run();

                if (! $init->isSuccessful()) {
                    return response()->json(['message' => 'git init a échoué : '.$init->getErrorOutput()], 422);
                }
            }
        }

        $project = Project::create($validated);

        return response()->json(['data' => new ProjectResource($project)], 201);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json(['data' => new ProjectResource($project)]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();
        $gitInit = (bool) ($validated['git_init'] ?? false);
        unset($validated['git_init']);

        $path = $validated['path'] ?? $project->path;

        if ($path) {
            if (! is_dir($path)) {
                if (! mkdir($path, 0755, true)) {
                    return response()->json(['message' => "Impossible de créer le dossier : {$path}"], 422);
                }
            }

            if ($gitInit) {
                $check = new Process(['git', 'rev-parse', '--git-dir'], $path);
                $check->run();

                if (! $check->isSuccessful()) {
                    $init = new Process(['git', 'init'], $path);
                    $init->run();

                    if (! $init->isSuccessful()) {
                        return response()->json(['message' => 'git init a échoué : '.$init->getErrorOutput()], 422);
                    }
                }
            }
        }

        $project->update($validated);

        return response()->json(['data' => new ProjectResource($project->fresh())]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json(null, 204);
    }
}
