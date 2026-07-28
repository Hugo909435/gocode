<?php

namespace App\Jobs;

use App\Agent\AgentEvent;
use App\Agent\AgentEventDispatcher;
use App\Agent\AgentEventType;
use App\Models\Session;
use App\Services\Agent\NvidiaClient;
use App\Services\Agent\ToolExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Boucle agentique function-calling pour un seul modèle NVIDIA (Kimi ou
 * DeepSeek), en mode read/plan/execute. Remplace ClaudeCodeJob : là où
 * Claude Code CLI embarquait ses propres outils, on les réimplémente ici
 * via ToolExecutor et on pilote la conversation nous-mêmes à coups de
 * chat/completions successifs (tool_calls → exécution → tool results →
 * nouvel appel), jusqu'à une réponse finale sans tool_calls.
 *
 * Contrairement à Claude Code (--resume côté CLI), il n'y a pas de session
 * serveur à reprendre : l'historique de conversation entre deux instructions
 * est reconstruit depuis la table `messages` (texte utilisateur + réponses
 * finales de l'agent uniquement — les allers-retours d'outils d'une
 * instruction ne sont pas rejoués dans les instructions suivantes, pour ne
 * pas faire exploser le contexte).
 */
class NvidiaAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $instruction,
        public readonly string $mode,
        public readonly string $modelKey,
        public readonly bool $resumed = false,
        public readonly string $actionId = '',
    ) {
        $this->timeout = (int) config('agent.drivers.nvidia.timeout', 300) + 120;
    }

    /**
     * Empêche deux instructions de tourner en parallèle sur la même session.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->sessionId))
                ->dontRelease()
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(AgentEventDispatcher $dispatcher, ToolExecutor $tools): void
    {
        $session = Session::with('project')->find($this->sessionId);

        if (! $session || ! $session->project) {
            return;
        }

        if ($this->resumed) {
            $this->resumeFromConfirmation($dispatcher, $tools, $session);

            return;
        }

        $project = $session->project;

        if (! $project->path || ! is_dir($project->path)) {
            $session->update(['status' => 'error', 'ended_at' => now()]);
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Error,
                $this->sessionId,
                ['message' => "Le chemin du projet est invalide ou inaccessible : {$project->path}"],
            ));

            return;
        }

        $config = config('agent.drivers.nvidia');
        $modelConfig = $config['models'][$this->modelKey] ?? null;

        if (! $modelConfig || ! $modelConfig['api_key']) {
            $this->emitFailure($dispatcher, $session, "Aucune clé API NVIDIA configurée pour le modèle [{$this->modelKey}].");

            return;
        }

        $canWrite = $this->mode === 'execute';
        $client = new NvidiaClient($config['base_url'], (int) $config['timeout']);
        $messages = $this->buildInitialMessages($session);

        $session->update([
            'status' => $this->initialStatus(),
            'started_at' => $session->started_at ?? now(),
        ]);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $this->sessionId,
            ['status' => $session->status],
        ));

        $this->runLoop(
            $dispatcher, $tools, $client, $session,
            $modelConfig['model'], $modelConfig['api_key'],
            $messages, $tools->definitions($canWrite),
            $canWrite, (bool) $config['enforce_whitelist'], (int) ($config['max_turns'] ?? 25),
        );
    }

    /**
     * Filet de sécurité : si le job crashe ou est tué par le worker, la
     * session ne doit pas rester figée côté UI.
     */
    public function failed(?Throwable $exception): void
    {
        $session = Session::find($this->sessionId);

        if (! $session || in_array($session->status, ['done', 'error', 'idle'])) {
            return;
        }

        $session->update(['status' => 'error', 'ended_at' => now()]);

        app(AgentEventDispatcher::class)->dispatch(AgentEvent::make(
            AgentEventType::Error,
            $this->sessionId,
            ['message' => 'Le job agent a échoué : '.($exception?->getMessage() ?? 'cause inconnue.')],
        ));
    }

    // -------------------------------------------------------------------------
    // Boucle agentique
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $toolDefs
     */
    private function runLoop(
        AgentEventDispatcher $dispatcher,
        ToolExecutor $tools,
        NvidiaClient $client,
        Session $session,
        string $model,
        string $apiKey,
        array $messages,
        array $toolDefs,
        bool $canWrite,
        bool $enforceWhitelist,
        int $maxTurns,
    ): void {
        for ($turn = 0; $turn < $maxTurns; $turn++) {
            if (Cache::pull("nvidia.stop.{$this->sessionId}")) {
                $session->update(['status' => 'idle', 'ended_at' => now()]);
                $dispatcher->dispatch(AgentEvent::make(
                    AgentEventType::Status,
                    $this->sessionId,
                    ['status' => 'idle', 'message' => 'Session arrêtée par l\'utilisateur.'],
                ));

                return;
            }

            try {
                $result = $client->chat($model, $apiKey, $messages, $toolDefs);
            } catch (Throwable $e) {
                $this->emitFailure($dispatcher, $session, $e->getMessage());

                return;
            }

            $this->recordUsage($dispatcher, $session, $result['usage'] ?? []);

            $assistantMessage = $result['message'];
            $toolCalls = $assistantMessage['tool_calls'] ?? [];

            if ($toolCalls === []) {
                $this->finish($dispatcher, $session, trim((string) ($assistantMessage['content'] ?? '')));

                return;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $assistantMessage['content'] ?? null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $index => $toolCall) {
                $name = $toolCall['function']['name'] ?? '';
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true) ?: [];

                if ($tools->isSensitive($name, $args)) {
                    $this->pauseForConfirmation($dispatcher, $session, $messages, $toolCalls, $index, $args);

                    return;
                }

                $output = $tools->run($session, $name, $args, $canWrite, $enforceWhitelist);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content' => $output,
                ];
            }
        }

        $session->update(['status' => 'error', 'ended_at' => now()]);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Error,
            $this->sessionId,
            ['message' => "Nombre maximum de tours agentiques atteint ({$maxTurns}) sans réponse finale."],
        ));
    }

    private function finish(AgentEventDispatcher $dispatcher, Session $session, string $text): void
    {
        if ($text !== '') {
            $eventType = $this->mode === 'plan' ? AgentEventType::Plan : AgentEventType::Message;
            $payloadKey = $this->mode === 'plan' ? 'content' : 'text';

            $dispatcher->dispatch(AgentEvent::make($eventType, $this->sessionId, [$payloadKey => $text]));
        }

        $session->update(['status' => 'done', 'ended_at' => now()]);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Done,
            $this->sessionId,
            ['status' => 'done', 'message' => 'Tâche terminée avec succès.'],
        ));
    }

    /**
     * `git commit` / `git push` exigent une confirmation humaine (garde-fou
     * CLAUDE.md). On sauvegarde l'état complet de la conversation en cache
     * et on arrête le job — NvidiaAgentDriver::confirmAction le reprendra.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    private function pauseForConfirmation(
        AgentEventDispatcher $dispatcher,
        Session $session,
        array $messages,
        array $toolCalls,
        int $pendingIndex,
        array $args,
    ): void {
        $actionId = (string) Str::uuid();
        $command = (string) ($args['command'] ?? '');

        Cache::put("nvidia.pending.{$this->sessionId}", [
            'action_id' => $actionId,
            'messages' => $messages,
            'tool_calls' => $toolCalls,
            'pending_index' => $pendingIndex,
            'model_key' => $this->modelKey,
            'mode' => $this->mode,
        ], now()->addHour());

        $session->update(['status' => 'awaiting_confirmation']);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::ConfirmationRequest,
            $this->sessionId,
            [
                'action_id' => $actionId,
                'type' => 'bash',
                'message' => "Exécuter la commande : {$command} ?",
                'command' => $command,
            ],
        ));
    }

    private function resumeFromConfirmation(AgentEventDispatcher $dispatcher, ToolExecutor $tools, Session $session): void
    {
        $cacheKey = "nvidia.pending.{$this->sessionId}";
        $pending = Cache::get($cacheKey);

        if (! $pending || $pending['action_id'] !== $this->actionId) {
            return;
        }

        Cache::forget($cacheKey);

        $project = $session->project;

        if (! $project->path || ! is_dir($project->path)) {
            $session->update(['status' => 'error', 'ended_at' => now()]);
            $dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Error,
                $this->sessionId,
                ['message' => "Le chemin du projet est invalide ou inaccessible : {$project->path}"],
            ));

            return;
        }

        $mode = $pending['mode'];
        $modelKey = $pending['model_key'];
        $canWrite = $mode === 'execute';

        $config = config('agent.drivers.nvidia');
        $modelConfig = $config['models'][$modelKey] ?? null;

        if (! $modelConfig || ! $modelConfig['api_key']) {
            $this->emitFailure($dispatcher, $session, "Aucune clé API NVIDIA configurée pour le modèle [{$modelKey}].");

            return;
        }

        $enforceWhitelist = (bool) $config['enforce_whitelist'];
        $client = new NvidiaClient($config['base_url'], (int) $config['timeout']);
        $toolDefs = $tools->definitions($canWrite);

        $messages = $pending['messages'];
        $toolCalls = $pending['tool_calls'];
        $index = $pending['pending_index'];

        $session->update(['status' => $mode === 'execute' ? 'building' : 'reading']);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $this->sessionId,
            ['status' => $session->status],
        ));

        // Résout d'abord l'appel d'outil confirmé, puis les éventuels appels
        // suivants du même tour (en s'arrêtant à nouveau si l'un d'eux est sensible).
        for ($i = $index; $i < count($toolCalls); $i++) {
            $toolCall = $toolCalls[$i];
            $name = $toolCall['function']['name'] ?? '';
            $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true) ?: [];

            if ($i > $index && $tools->isSensitive($name, $args)) {
                $this->pauseForConfirmation($dispatcher, $session, $messages, $toolCalls, $i, $args);

                return;
            }

            $output = $tools->run($session, $name, $args, $canWrite, $enforceWhitelist);
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'] ?? '',
                'content' => $output,
            ];
        }

        $this->runLoop(
            $dispatcher, $tools, $client, $session,
            $modelConfig['model'], $modelConfig['api_key'],
            $messages, $toolDefs,
            $canWrite, $enforceWhitelist, (int) ($config['max_turns'] ?? 25),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function initialStatus(): string
    {
        return match ($this->mode) {
            'plan' => 'planning',
            'execute' => 'building',
            default => 'reading',
        };
    }

    /**
     * Reconstruit l'historique de conversation pour l'API à partir des
     * messages persistés : instructions utilisateur + réponses finales de
     * l'agent (texte/plan). Les tool_calls intermédiaires d'une instruction
     * passée ne sont pas rejoués, par souci de taille de contexte.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildInitialMessages(Session $session): array
    {
        $messages = [['role' => 'system', 'content' => $this->buildSystemPrompt($session)]];

        foreach ($session->messages()->orderBy('id')->get() as $message) {
            if ($message->role === 'user' && $message->type === 'text') {
                $messages[] = ['role' => 'user', 'content' => $message->content];

                continue;
            }

            if ($message->role === 'agent' && in_array($message->type, ['text', 'plan'], true)) {
                $decoded = json_decode($message->content, true) ?: [];
                $text = trim((string) ($decoded['text'] ?? $decoded['content'] ?? ''));

                if ($text !== '') {
                    $messages[] = ['role' => 'assistant', 'content' => $text];
                }
            }
        }

        return $messages;
    }

    private function buildSystemPrompt(Session $session): string
    {
        $project = $session->project;

        $lines = ["Tu es un agent de code qui travaille sur le projet **{$project->name}**."];

        if ($project->stack) {
            $lines[] = "Stack : {$project->stack}";
        }

        if ($project->description) {
            $lines[] = "Description : {$project->description}";
        }

        $availableTools = 'read_file, list_dir, glob_search, grep_search';
        if ($this->mode === 'execute') {
            $availableTools .= ', write_file, edit_file, bash_command';
        }

        $lines[] = "Utilise exclusivement les outils fournis ({$availableTools}) pour explorer".
            ($this->mode === 'execute' ? ' et modifier' : '').
            " le code — ne suppose jamais le contenu d'un fichier sans l'avoir lu.";

        $lines[] = match ($this->mode) {
            'read' => 'Mode lecture seule : réponds à la demande sans proposer ni exécuter aucune modification.',
            'plan' => "Mode plan : explore le code puis produis un plan d'action structuré en markdown. Ne modifie rien, n'exécute aucune commande.",
            'execute' => 'Mode exécution : réalise la tâche de bout en bout (lecture, édition, commandes). '.
                'Ne lance `git commit` ou `git push` que si la tâche le requiert explicitement — ces commandes '.
                'déclenchent une confirmation humaine obligatoire et bloquent tant qu\'elle n\'est pas accordée.',
            default => '',
        };

        $lines[] = "Réponds en français. Une fois la tâche terminée, produis un dernier message sans appel d'outil résumant ce qui a été fait.";

        return implode("\n", $lines);
    }

    private function recordUsage(AgentEventDispatcher $dispatcher, Session $session, array $usage): void
    {
        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);

        if ($inputTokens === 0 && $outputTokens === 0) {
            return;
        }

        $session->update([
            'input_tokens' => ($session->input_tokens ?? 0) + $inputTokens,
            'output_tokens' => ($session->output_tokens ?? 0) + $outputTokens,
        ]);

        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Cost,
            $this->sessionId,
            [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => 0,
            ],
        ));
    }

    private function emitFailure(AgentEventDispatcher $dispatcher, Session $session, string $message): void
    {
        $session->update(['status' => 'error', 'ended_at' => now()]);
        $dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Error,
            $this->sessionId,
            ['message' => $message],
        ));
    }
}
