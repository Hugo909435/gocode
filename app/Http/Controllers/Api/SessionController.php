<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Session\ConfirmActionRequest;
use App\Http\Requests\Session\SendInstructionRequest;
use App\Http\Requests\Session\StoreSessionRequest;
use App\Http\Requests\Session\UpdateSessionRequest;
use App\Http\Resources\SessionResource;
use App\Models\Project;
use App\Models\Session;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionService $service,
    ) {}

    public function index(Project $project): JsonResponse
    {
        $sessions = $project->sessions()->orderByDesc('created_at')->get();

        return response()->json(['data' => SessionResource::collection($sessions)]);
    }

    public function store(StoreSessionRequest $request, Project $project): JsonResponse
    {
        $session = $this->service->create($project, $request->validated());

        return response()->json(['data' => new SessionResource($session)], 201);
    }

    public function show(Session $session): JsonResponse
    {
        $session->load('messages');

        return response()->json(['data' => new SessionResource($session)]);
    }

    public function sendInstruction(SendInstructionRequest $request, Session $session): JsonResponse
    {
        $this->service->sendInstruction(
            $session,
            $request->input('instruction'),
            $request->input('mode'),
        );

        return response()->json(['data' => new SessionResource($session->fresh())]);
    }

    public function confirm(ConfirmActionRequest $request, Session $session): JsonResponse
    {
        $this->service->confirmAction(
            $session,
            $request->input('action_id'),
            $request->boolean('approved'),
        );

        return response()->json(['data' => new SessionResource($session->fresh())]);
    }

    public function stop(Session $session): JsonResponse
    {
        $this->service->stop($session);

        return response()->json(['data' => new SessionResource($session->fresh())]);
    }

    public function update(UpdateSessionRequest $request, Session $session): JsonResponse
    {
        $session = $this->service->update($session, $request->validated());

        return response()->json(['data' => new SessionResource($session)]);
    }
}
