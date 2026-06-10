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
use App\Http\Resources\MessageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        if (! $project->path || ! is_dir($project->path)) {
            return response()->json([
                'message' => 'Ce projet n\'a pas de chemin local valide. Configurez un chemin ou liez-le à GitHub avant de démarrer une session.',
            ], 422);
        }

        $session = $this->service->create($project, $request->validated());

        return response()->json(['data' => new SessionResource($session)], 201);
    }

    public function show(Session $session): JsonResponse
    {
        $session->load(['messages', 'project']);

        return response()->json(['data' => new SessionResource($session)]);
    }

    public function sendInstruction(SendInstructionRequest $request, Session $session): JsonResponse
    {
        $this->service->sendInstruction(
            $session,
            $request->input('instruction'),
            $request->input('mode'),
            $request->input('skills', []),
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

    public function clearMessages(Session $session): JsonResponse
    {
        $session->messages()->delete();
        $session->update(['status' => 'idle']);

        return response()->json(['data' => new SessionResource($session->fresh())]);
    }

    /**
     * Retourne les nouveaux messages depuis le curseur donné + le statut de la session.
     * Utilisé par le frontend en polling (compatible serveur mono-processus Windows).
     *
     * GET /api/sessions/{session}/poll?cursor=0
     */
    public function poll(Request $request, Session $session): JsonResponse
    {
        $cursor   = (int) $request->query('cursor', 0);
        $messages = $session->messages()
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->get();

        $session->load('project');

        return response()->json([
            'session'  => new SessionResource($session),
            'messages' => MessageResource::collection($messages),
        ]);
    }
}
